<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/** Team-scoped storage. Identifiers are from the installation's configured prefix or constants only. */
final class LcCrmStore
{
    public const TYPES = ['company','contact','deal','task','case','note'];
    public const STAGES = ['inquiry','qualified','proposal','negotiation','won','lost'];
    public const STATUSES = ['company'=>['active','archived'], 'contact'=>['active','archived'], 'deal'=>self::STAGES,
        'task'=>['open','done'], 'case'=>['new','working','resolved'], 'note'=>['note','call']];
    public const FIELDS = ['company'=>['tags'], 'contact'=>['email','phone','tags','participant_id'],
        'deal'=>['amount','next_action','loss_reason','closed_at','currency','email','person','company_name'], 'case'=>['priority','resolution','email','order','sku','score','item_feedback','customer_ref','person','company_name'],
        'task'=>[], 'note'=>['body']];
    private PDO $db;
    private string $prefix;

    public function __construct(PDO $db, string $prefix)
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D',$prefix)) { throw new RuntimeException('Unsupported database prefix.'); }
        $this->db = $db;
        $this->prefix = $prefix;
    }

    public function table(string $name): string { return $this->prefix.$name; }
    private function driver(): string { return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME); }
    private function query(string $sql, array $values = []): PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        return $stmt;
    }
    public function one(string $sql, array $values = []): ?array { return $this->query($sql,$values)->fetch(PDO::FETCH_ASSOC) ?: null; }
    public function all(string $sql, array $values = []): array { return $this->query($sql,$values)->fetchAll(PDO::FETCH_ASSOC); }
    public function atomic(callable $fn)
    {
        if ($this->db->inTransaction()) { return $fn(); }
        $this->db->beginTransaction();
        try { $result=$fn(); $this->db->commit(); return $result; }
        catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    /** Serialize DDL across concurrent web/worker entry points during a live update. */
    public function upgrade(callable $migration): void
    {
        $driver=$this->driver(); $key='lc_crm_schema_'.substr(hash('sha256',$this->prefix),0,20);
        if ($driver==='mysql' && (int)$this->query('SELECT GET_LOCK(?, 30)',[$key])->fetchColumn()!==1) {throw new RuntimeException('CRM schema update busy. Please retry.');}
        if ($driver==='pgsql') {$this->query('SELECT pg_advisory_lock(hashtext(?))',[$key]);}
        try {$migration();}
        finally {
            if ($driver==='mysql') {$this->query('SELECT RELEASE_LOCK(?)',[$key]);}
            elseif ($driver==='pgsql') {$this->query('SELECT pg_advisory_unlock(hashtext(?))',[$key]);}
        }
    }
    public function install(): void
    {
        $pk = match ($this->driver()) {'mysql'=>'INTEGER PRIMARY KEY AUTO_INCREMENT', 'pgsql'=>'SERIAL PRIMARY KEY', 'sqlite'=>'INTEGER PRIMARY KEY', default=>throw new RuntimeException('Unsupported CRM database.')};
        $team=$this->table('lc_crm_team'); $rec=$this->table('lc_crm_record'); $history=$this->table('lc_crm_history'); $map=$this->table('lc_crm_survey');
        $this->query("CREATE TABLE IF NOT EXISTS $team (team_id INTEGER PRIMARY KEY)");
        $this->query("CREATE TABLE IF NOT EXISTS $rec (id $pk, team_id INTEGER NOT NULL, type VARCHAR(12) NOT NULL, title VARCHAR(180) NOT NULL,
            owner_id INTEGER NOT NULL DEFAULT 0, parent_id INTEGER NULL, status VARCHAR(24) NOT NULL, due VARCHAR(10) NULL,
            created VARCHAR(19) NOT NULL, updated VARCHAR(19) NOT NULL, revision INTEGER NOT NULL DEFAULT 1, details TEXT NOT NULL, search_text TEXT NULL,
            source_key VARCHAR(64) NULL, identity_key VARCHAR(64) NULL, UNIQUE(team_id,source_key), UNIQUE(team_id,identity_key))");
        $this->query("CREATE TABLE IF NOT EXISTS $history (id $pk, team_id INTEGER NOT NULL, record_id INTEGER NOT NULL, actor_id INTEGER NOT NULL,
            happened VARCHAR(19) NOT NULL, description VARCHAR(255) NOT NULL)");
        $this->query("CREATE TABLE IF NOT EXISTS $map (survey_id INTEGER PRIMARY KEY, team_id INTEGER NOT NULL, store_key VARCHAR(160) NOT NULL,
            optin_code VARCHAR(30) NOT NULL, name_code VARCHAR(30) NOT NULL, email_code VARCHAR(30) NOT NULL, company_code VARCHAR(30) NOT NULL, subject_code VARCHAR(30) NOT NULL, form_target VARCHAR(8) NOT NULL DEFAULT 'deal')");
        // PostgreSQL installations with C collation do not fold Polish letters in LOWER().
        // Backfill saved rows on reactivation and keep the same fold for search input and writes.
        if (!$this->hasColumn($rec,'search_text')) {$this->query("ALTER TABLE $rec ADD COLUMN search_text TEXT NULL");}
        if (!$this->hasColumn($map,'form_target')) {$this->query("ALTER TABLE $map ADD COLUMN form_target VARCHAR(8) NOT NULL DEFAULT 'deal'");}
        do {
            $legacy=$this->all("SELECT id, title, details, revision FROM $rec WHERE search_text IS NULL ORDER BY id LIMIT 250");
            foreach ($legacy as $row) {
                $this->query("UPDATE $rec SET search_text=? WHERE id=? AND revision=? AND search_text IS NULL",[self::searchText($row['title'],$row['details']),$row['id'],$row['revision']]);
            }
        } while (count($legacy)===250);
        foreach ([[$rec,'team_id,type,owner_id','scope'],[$rec,'team_id,parent_id','parent'],[$history,'team_id,record_id','hist']] as [$table,$cols,$suffix]) {
            $idx=substr(hash('sha256',$table),0,10).'_'.$suffix;
            if ($this->driver()==='mysql') {
                if (!$this->one("SHOW INDEX FROM $table WHERE Key_name = ?", ['lc_'.$idx])) { $this->query("CREATE INDEX lc_$idx ON $table ($cols)"); }
            } else { $this->query("CREATE INDEX IF NOT EXISTS lc_$idx ON $table ($cols)"); }
        }
    }
    private function hasColumn(string $table,string $name): bool
    {
        $columns=$this->query("SELECT * FROM $table WHERE 1=0");
        try {
            for ($i=0;$i<$columns->columnCount();$i++) {
                if (($columns->getColumnMeta($i)['name']??'')===$name) {return true;}
            }
            return false;
        } finally {$columns->closeCursor();}
    }
    public function enable(int $team): void
    {
        if (!$this->enabled($team)) { $this->query('INSERT INTO '.$this->table('lc_crm_team').' (team_id) VALUES (?)',[$team]); }
    }
    public function enabled(int $team): bool { return (bool)$this->one('SELECT team_id FROM '.$this->table('lc_crm_team').' WHERE team_id=?',[$team]); }
    public function members(int $team): array
    {
        $u=$this->table('users'); $g=$this->table('user_groups'); $m=$this->table('user_in_groups');
        return $this->all("SELECT u.uid,u.users_name FROM $u u WHERE u.uid IN (SELECT uid FROM $m WHERE ugid=? UNION SELECT owner_id FROM $g WHERE ugid=?) ORDER BY u.users_name",[$team,$team]);
    }
    public function memberRole(int $team,int $uid): ?string
    {
        if (!$uid || !$this->enabled($team)) {return null;}
        $group=$this->one('SELECT owner_id FROM '.$this->table('user_groups').' WHERE ugid=?',[$team]);
        $user=$this->one('SELECT uid,user_status,expires FROM '.$this->table('users').' WHERE uid=?',[$uid]);
        if (!$group || !$user || !in_array($user['user_status'],[true,1,'1','t'],true) || ($user['expires'] && strtotime($user['expires'])<=time())) {return null;}
        if ((int)$group['owner_id']===$uid) {return 'manager';}
        return $this->one('SELECT uid FROM '.$this->table('user_in_groups').' WHERE ugid=? AND uid=?',[$team,$uid]) ? 'worker' : null;
    }
    private function inflate(?array $row): ?array
    {
        if (!$row) {return null;}
        $detail=json_decode($row['details'],true,32,JSON_THROW_ON_ERROR);
        unset($row['details'],$row['source_key'],$row['identity_key'],$row['search_text']);
        foreach (['id','team_id','owner_id','revision'] as $key) {$row[$key]=(int)$row[$key];}
        return $row+$detail;
    }
    public function get(int $team,int $id): ?array
    {
        return $this->inflate($this->one('SELECT * FROM '.$this->table('lc_crm_record').' WHERE team_id=? AND id=?',[$team,$id]));
    }
    public function listing(int $team,string $type,?int $owner=null,string $search='',string $filter='',int $parent=0,int $offset=0,?int $limit=100): array
    {
        if (!in_array($type,self::TYPES,true)) {throw new DomainException('Nieznany typ rekordu.');}
        $where=['team_id=?','type=?']; $args=[$team,$type];
        if ($owner!==null) {$where[]='owner_id=?'; $args[]=$owner;}
        if ($parent) {$where[]='parent_id=?'; $args[]=$parent;}
        if ($search!=='') {
            $needle='%'.strtr(mb_strtolower($search),['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $where[]="search_text LIKE ? ESCAPE '!'"; $args[]=$needle;
        }
        if (in_array($filter,['today','overdue'],true)) {
            $where[]=$filter==='today'?'due=?':'due<?'; $args[]=date('Y-m-d'); $where[]="status NOT IN ('done','resolved','won','lost')";
        } elseif ($filter==='unassigned') {$where[]='owner_id=0';}
        elseif ($filter!=='') {$where[]='status=?'; $args[]=$filter;}
        $limitSql=$limit===null?'':' LIMIT '.max(1,$limit).' OFFSET '.max(0,$offset);
        return array_map(fn($row)=>$this->inflate($row),$this->all('SELECT * FROM '.$this->table('lc_crm_record').' WHERE '.implode(' AND ',$where).' ORDER BY id DESC'.$limitSql,$args));
    }
    public function history(int $team,int $id): array {return $this->all('SELECT actor_id,happened,description FROM '.$this->table('lc_crm_history').' WHERE team_id=? AND record_id=? ORDER BY id DESC',[$team,$id]);}
    public function lookupContact(int $team,int $company,string $email): ?array
    {
        if ($email==='') {return null;}
        return $this->inflate($this->one('SELECT * FROM '.$this->table('lc_crm_record').' WHERE team_id=? AND identity_key=?',[$team,self::identity($company,$email)]));
    }
    private static function searchText(string $title,string $details): string
    {
        return mb_strtolower($title."\n".implode("\n",json_decode($details,true,32,JSON_THROW_ON_ERROR)), 'UTF-8');
    }
    private static function identity(int $parent,string $email): string {return hash('sha256', $parent.'|'.strtolower(trim($email)));}
    private static function bounded($value,int $max=255): string
    {
        if (!is_scalar($value) && $value!==null) {throw new DomainException('Nieprawidłowa wartość pola.');}
        $s=trim((string)$value);
        if (mb_strlen($s)>$max || str_contains($s,"\0")) {throw new DomainException('Nieprawidłowa długość pola.');}
        return $s;
    }
    public function save(int $team,string $type,array $data,int $actor,?array $old=null,?string $source=null): array
    {
        if (!isset(self::STATUSES[$type]) || ($old && ($old['team_id']!==$team || $old['type']!==$type))) {throw new DomainException('Nieprawidłowy rekord.');}
        $title=self::bounded($data['title']??'',180);
        $state=self::bounded($data['status']??self::STATUSES[$type][0],24);
        if ($title==='' || !in_array($state,self::STATUSES[$type],true)) {throw new DomainException('Wymagane są nazwa i prawidłowy status.');}
        $owner=(int)($data['owner_id']??0); $parent=(int)($data['parent_id']??0); $due=self::bounded($data['due']??'',10);
        if ($owner!==0 && !$this->memberRole($team,$owner)) {throw new DomainException('Opiekun musi należeć do zespołu.');}
        if ($parent) {
            $target=$this->get($team,$parent);
            $allowed=match($type) {'contact'=>['company'],'deal','case'=>['company','contact'],'task','note'=>['company','contact','deal','case'],default=>[]};
            if (!$target || !in_array($target['type'],$allowed,true)) {throw new DomainException('Powiązanie musi wskazywać właściwy rekord w zespole.');}
        }
        if ($due!=='' && !(($d=DateTimeImmutable::createFromFormat('!Y-m-d',$due)) && $d->format('Y-m-d')===$due)) {throw new DomainException('Nieprawidłowa data.');}
        $fields=[];
        foreach(self::FIELDS[$type] as $key) {$fields[$key]=self::bounded($data[$key]??'',$key==='item_feedback'?16000:(in_array($key,['body','resolution','loss_reason','next_action'],true)?4000:240));}
        if (!empty($fields['email']) && !filter_var($fields['email'],FILTER_VALIDATE_EMAIL)) {throw new DomainException('Nieprawidłowy adres e-mail.');}
        if ($type==='deal') {
            if ($fields['amount']!=='' && !preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D',$fields['amount'])) {throw new DomainException('Kwota wymaga liczby np. 1250.50.');}
            $fields['currency']=strtoupper($fields['currency']?:'PLN');
            if (!preg_match('/^[A-Z]{3}$/D',$fields['currency'])) {throw new DomainException('Podaj trzyliterowy kod waluty.');}
            if ($state==='lost' && $fields['loss_reason']==='') {throw new DomainException('Podaj powód utraty.');}
            $fields['closed_at']=in_array($state,['won','lost'],true) ? (($old && $old['status']===$state && !empty($old['closed_at'])) ? $old['closed_at'] : date('Y-m-d')) : '';
        }
        if ($type==='case') {
            $fields['priority']=$fields['priority']?:'normal';
            if (!in_array($fields['priority'],['low','normal','high','urgent'],true) || ($state==='resolved' && $fields['resolution']==='')) {throw new DomainException('Podaj priorytet i sposób rozwiązania zamkniętej sprawy.');}
        }
        if ($type==='note' && ($parent===0 || $fields['body']==='')) {throw new DomainException('Aktywność wymaga rekordu i treści.');}
        $identity=$type==='contact' && $fields['email']!=='' ? self::identity($parent,$fields['email']) : null;
        $record=$this->table('lc_crm_record'); $now=gmdate('Y-m-d H:i:s');
        $details=json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $values=[$title,$owner,$parent?:null,$state,$due?:null,$now,$details,self::searchText($title,$details),$identity];
        return $this->atomic(function () use ($record,$values,$team,$type,$actor,$old,$source,$now,$state,$owner,$data) {
            if ($old) {
                $id=$old['id']; $revision=(int)($data['revision']??0);
                $stmt=$this->query("UPDATE $record SET title=?,owner_id=?,parent_id=?,status=?,due=?,updated=?,details=?,search_text=?,identity_key=?,revision=revision+1 WHERE team_id=? AND id=? AND revision=?",array_merge($values,[$team,$id,$revision]));
                if ($stmt->rowCount()!==1) {throw new DomainException('Rekord został zmieniony. Odśwież widok.');}
            } else {
                $sql="INSERT INTO $record (title,owner_id,parent_id,status,due,updated,details,search_text,identity_key,team_id,type,source_key,created) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
                $args=array_merge($values,[$team,$type,$source,$now]);
                if ($this->driver()==='pgsql') {$id=(int)$this->query($sql.' RETURNING id',$args)->fetchColumn();}
                else {$this->query($sql,$args); $id=(int)$this->db->lastInsertId();}
            }
            $changes=[];
            if (!$old || $old['owner_id']!==$owner) {$changes[]='Opiekun #'.($old['owner_id']??0).' → #'.$owner;}
            if (!$old || $old['status']!==$state) {$changes[]=($old['status']??'Utworzono').' → '.$state;}
            $this->query('INSERT INTO '.$this->table('lc_crm_history').' (team_id,record_id,actor_id,happened,description) VALUES (?,?,?,?,?)',[$team,$id,$actor,$now,implode(', ',$changes)?:'Zapisano zmiany']);
            return $this->get($team,$id);
        });
    }
    /** Shared addresses across companies are ambiguous. Do not assign a contact on that basis. */
    public function caseAssignment(int $team,string $email): array
    {
        $match=null;
        for ($offset=0;;$offset+=50) {
            $page=$this->listing($team,'contact',null,$email,'',0,$offset,50);
            foreach ($page as $contact) {
                if (strcasecmp($contact['email'],$email)!==0) {continue;}
                if ($match!==null) {$match=null;break 2;}
                $match=$contact;
            }
            if (count($page)<50) {break;}
        }
        $parent=0;
        if ($match!==null && $match['status']==='active') {
            $parent=$match['id'];
            if ($this->memberRole($team,$match['owner_id'])) {return ['parent_id'=>$parent,'owner_id'=>$match['owner_id']];}
        }
        $group=$this->one('SELECT owner_id FROM '.$this->table('user_groups').' WHERE ugid=?',[$team]);
        $manager=(int)($group['owner_id']??0);
        return ['parent_id'=>$parent,'owner_id'=>$this->memberRole($team,$manager)==='manager'?$manager:0];
    }
    public function source(int $team,string $key): ?array {return $this->inflate($this->one('SELECT * FROM '.$this->table('lc_crm_record').' WHERE team_id=? AND source_key=?',[$team,$key]));}
    public function mapping(int $sid): ?array {return $this->one('SELECT * FROM '.$this->table('lc_crm_survey').' WHERE survey_id=?',[$sid]);}
    public function setMapping(int $team,int $sid,string $store,string $consent,string $name,string $email,string $company='',string $subject='',string $target='deal'): void
    {
        if (!in_array($target,['deal','case'],true)) {throw new DomainException('Wybierz przeznaczenie formularza.');}
        $existing=$this->mapping($sid);
        if ($existing && (int)$existing['team_id']!==$team) {throw new DomainException('Ta ankieta należy do innego zespołu.');}
        $store=self::bounded($store,160);
        if ($consent!=='' || $name!=='' || $email!=='') {
            foreach ([$consent,$name,$email] as $code) {if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,29}$/D',$code)){throw new DomainException('Podaj trzy kody pytań do obsługi zapytania.');}}
        }
        foreach ([$company,$subject] as $code) {if ($code!=='' && !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,29}$/D',$code)) {throw new DomainException('Nieprawidłowy kod pytania.');}}
        if ($existing) {$this->query('UPDATE '.$this->table('lc_crm_survey').' SET store_key=?, optin_code=?, name_code=?, email_code=?, company_code=?,subject_code=?,form_target=? WHERE team_id=? AND survey_id=?',[$store,$consent,$name,$email,$company,$subject,$target,$team,$sid]);}
        else {$this->query('INSERT INTO '.$this->table('lc_crm_survey').' (store_key,optin_code,name_code,email_code,company_code,subject_code,form_target,team_id,survey_id) VALUES (?,?,?,?,?,?,?,?,?)',[$store,$consent,$name,$email,$company,$subject,$target,$team,$sid]);}
    }
}
