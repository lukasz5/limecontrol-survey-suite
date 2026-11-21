<?php
// SPDX-License-Identifier: GPL-2.0-or-later
final class LcCrmPanel
{
    public const LABELS=['company'=>'Firmy','contact'=>'Kontakty','deal'=>'Szanse sprzedaży','task'=>'Zadania','case'=>'Sprawy','note'=>'Notatki i rozmowy'];
    public const TEXT=['inquiry'=>'Zapytanie','qualified'=>'Zakwalifikowana','proposal'=>'Oferta','negotiation'=>'Negocjacje','won'=>'Wygrana','lost'=>'Przegrana','new'=>'Nowa','working'=>'W realizacji',
        'resolved'=>'Rozwiązana','open'=>'Otwarte','done'=>'Wykonane','active'=>'Aktywny','archived'=>'Archiwalny','note'=>'Notatka','call'=>'Rozmowa','low'=>'Niski','normal'=>'Normalny','high'=>'Wysoki','urgent'=>'Pilny',
        'admin'=>'Administrator','manager'=>'Kierownik','worker'=>'Pracownik','title'=>'Nazwa / temat','status'=>'Etap lub status','owner_id'=>'Opiekun','parent_id'=>'Powiązany rekord','due'=>'Termin','tags'=>'Tagi','email'=>'E-mail',
        'phone'=>'Telefon','amount'=>'Kwota','currency'=>'Waluta','next_action'=>'Następny krok','loss_reason'=>'Powód utraty','closed_at'=>'Data rozstrzygnięcia','priority'=>'Priorytet','resolution'=>'Sposób rozwiązania',
        'person'=>'Osoba zgłaszająca','company_name'=>'Firma zgłaszająca','body'=>'Treść','order'=>'Zamówienie','sku'=>'SKU','score'=>'Ocena','item_feedback'=>'Oceny zakupionych produktów','customer_ref'=>'Identyfikator klienta w sklepie','participant_id'=>'Identyfikator CPDB'];
    public LcCrmStore $s;
    public int $team=0,$id=0,$uid;
    public string $role='', $type='deal',$tab='board',$notice='', $error='';
    public array $teams=[], $groups=[], $members=[], $row=[], $preview=[], $draft=[];
    private $req;
    public function __construct(LcCrmStore $s) {$this->s=$s; $this->uid=LimeControlCrm::uid(); $this->req=App()->request;}
    public static function e($v): string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function label(string $v): string {return self::TEXT[$v]??$v;}
    public function str(string $key,string $default=''): string {$v=$this->req->getParam($key,$default);return is_scalar($v)?(string)$v:$default;}
    public function link(array $args=[]): string {return self::e(LimeControlCrm::url($args+['team'=>$this->team]));}
    public function csrf(): string {return CHtml::hiddenField($this->req->csrfTokenName,$this->req->getCsrfToken());}
    private function privileged(): void {if ($this->role==='worker') {throw new CHttpException(403,'Wymagana rola kierownika.');}}
    public function scope(): ?int {return $this->role==='worker'?$this->uid:null;}
    public function run(): void
    {
        $s=$this->s;
        if (LimeControlCrm::admin()) {$this->groups=$s->all('SELECT ugid,name FROM '.$s->table('user_groups').' ORDER BY name');}
        foreach($s->all('SELECT g.ugid,g.name FROM '.$s->table('user_groups').' g JOIN '.$s->table('lc_crm_team').' t ON g.ugid=t.team_id ORDER BY g.name') as $g) {
            if (LimeControlCrm::admin() || $s->memberRole((int)$g['ugid'],$this->uid)) {$this->teams[(int)$g['ugid']]=$g['name'];}
        }
        $this->team=(int)$this->str('team',(string)(array_key_first($this->teams)??0));
        if ($this->team) {$this->role=LimeControlCrm::teamRole($this->team); $this->members=array_column($s->members($this->team),'users_name','uid');}
        $this->type=$this->str('type','deal'); $this->tab=$this->str('tab','board'); $this->id=(int)$this->str('id');
        if (!isset(self::LABELS[$this->type]) || !in_array($this->tab,['board','list','record','edit','reports','csv','settings'],true)) {throw new CHttpException(404);}
        if ($this->id) {$this->row=LimeControlCrm::recordContext($this->team,$this->type,$this->id);}
        if ($this->req->getIsPostRequest()) {
            $csrf=$this->req->getPost($this->req->csrfTokenName);
            if (!is_string($csrf) || !hash_equals($this->req->getCsrfToken(),$csrf)) {throw new CHttpException(400,'Odśwież formularz.');}
            try {$this->post();}
            catch(DomainException $e) {$this->error=$e->getMessage();}
            catch(PDOException $e) {
                if (str_starts_with((string)$e->getCode(),'23')) {$this->error='Konflikt z istniejącym rekordem. Sprawdź duplikat i spróbuj ponownie.';}
                else {throw $e;}
            }
        }
        if ($this->team && in_array($this->tab,['reports','csv','settings'],true)) {$this->privileged();}
        if ($this->team && $this->tab==='csv') {
            if ($this->str('download')==='1') {
                header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="crm-kontakty.csv"'); header('Cache-Control: private, no-store');
                LcCrmCsv::export($s,$this->team,fopen('php://output','wb')); App()->end(); return;
            }
            $this->preview=App()->session[$this->previewKey()]??[];
        }
        $key='lc_crm_notice:'.$this->uid; $this->notice=App()->session[$key]??''; unset(App()->session[$key]);
        header('Content-Type: text/html; charset=UTF-8'); header('Cache-Control: private, no-store'); header('Referrer-Policy: same-origin');
        require __DIR__.'/panel.php'; App()->end();
    }
    private function previewKey(): string {return 'lc_crm_preview:'.$this->uid.':'.$this->team;}
    private function done(string $msg,array $args): void
    {
        App()->session['lc_crm_notice:'.$this->uid]=$msg; App()->getController()->redirect(LimeControlCrm::url($args+['team'=>$this->team]));
    }
    private function post(): void
    {
        $action=$this->req->getPost('action'); $s=$this->s;
        if ($action==='enable') {
            if (!LimeControlCrm::admin()) {throw new CHttpException(403);}
            $id=(int)$this->req->getPost('group'); if (!$id || !UserGroup::model()->findByPk($id)) {throw new DomainException('Wybierz grupę administratorów LimeSurvey.');}
            $s->enable($id); $this->done('Zespół gotowy do pracy.',['team'=>$id]);return;
        }
        if (!$this->team) {throw new CHttpException(403);}
        switch ($action) {
        case 'save':
            $posted=$this->req->getPost('record',[]); if(!is_array($posted)){throw new CHttpException(400);}
            $this->draft=$posted; $old=$this->id ? LimeControlCrm::recordContext($this->team,$this->type,$this->id,true) : null;
            $data=$posted+($old??[]); $data['participant_id']=$old['participant_id']??'';
            foreach (['order','sku','score','closed_at','item_feedback','customer_ref'] as $key) {$data[$key]=$old[$key]??'';}
            if ($this->role==='worker') {$data['owner_id']=$this->uid;}
            if ($parent=(int)($data['parent_id']??0)) {
                $p=$s->get($this->team,$parent); if (!$p) {throw new DomainException('Nieprawidłowe powiązanie.');}
                if (($old['parent_id']??0)!=$parent) {LimeControlCrm::recordContext($this->team,$p['type'],$parent);}
            }
            $r=$s->save($this->team,$this->type,$data,$this->uid,$old);
            $this->done('Zapisano rekord.',['tab'=>'record','type'=>$this->type,'id'=>$r['id']]); return;
        case 'mapping':
            if (!LimeControlCrm::admin()) {throw new CHttpException(403);}
            $sid=(int)$this->str('survey'); $survey=Survey::model()->findByPk($sid);
            if (!$survey || $survey->anonymized!=='N') {throw new DomainException('Wybierz ankietę z identyfikacją uczestników.');}
            $codes=array_map(fn($k)=>trim($this->str($k)),['consent','person','email','company','subject']);
            if (array_filter($codes) && (!array_product(array_map('strlen',array_slice($codes,0,3))) || !LimeControlCrm::leadFields($survey,$codes))) {throw new DomainException('Sprawdź kody: zgoda typu Tak/Nie, pozostałe pola tekstowe, każde pytanie odrębne, bez szyfrowania.');}
            $s->setMapping($this->team,$sid,trim($this->str('store')),...array_merge($codes,[$this->str('form_target','deal')])); $this->done('Zapisano mapowanie ankiety.',['tab'=>'settings']); return;
        case 'cpdb':
            if (!LimeControlCrm::admin() || $this->type!=='contact' || !$this->id) {throw new CHttpException(403);}
            $r=LimeControlCrm::recordContext($this->team,'contact',$this->id,true); $sid=(int)$this->str('survey'); $tid=(int)$this->str('tid');
            $map=$s->mapping($sid); $survey=Survey::model()->findByPk($sid);
            if (!$map || (int)$map['team_id']!==$this->team || !$survey || $survey->anonymized!=='N' || !$survey->getHasTokensTable()) {throw new DomainException('Wybierz zmapowaną ankietę z identyfikacją i tabelą uczestników.');}
            $t=Token::model($sid)->findByPk($tid);
            if (!$t || !$t->participant_id || strcasecmp((string)$t->email,(string)$r['email'])!==0 || !Participant::model()->findByPk($t->participant_id)) {throw new DomainException('Nie znaleziono uczestnika o zgodnym adresie e-mail powiązanego z CPDB.');}
            $s->save($this->team,'contact',['participant_id'=>$t->participant_id,'revision'=>(int)$this->str('revision')]+$r,$this->uid,$r);
            $this->done('Połączono wskazanego uczestnika.',['type'=>'contact','tab'=>'record','id'=>$this->id]); return;
        case 'preview':
            $this->privileged(); $this->tab='csv'; $file=$_FILES['csv']??null;
            if (!$file || $file['error']!==UPLOAD_ERR_OK || $file['size']>524288 || !is_uploaded_file($file['tmp_name'])) {throw new DomainException('Wybierz plik CSV do 512 KiB.');}
            $rows=LcCrmCsv::preview($s,$this->team,(string)file_get_contents($file['tmp_name']));
            App()->session[$this->previewKey()]=['rows'=>$rows,'nonce'=>bin2hex(random_bytes(16))]; $this->done('Sprawdź proponowane zmiany.',['tab'=>'csv']);return;
        case 'import':
            $this->privileged(); $p=App()->session[$this->previewKey()]??[];
            if (!$p || !hash_equals($p['nonce'],$this->str('nonce'))) {throw new DomainException('Wczytaj podgląd ponownie.');}
            $merge=$this->req->getPost('merge',[]); $n=LcCrmCsv::apply($s,$this->team,$this->uid,$p['rows'],is_array($merge)?$merge:[]);
            unset(App()->session[$this->previewKey()]); $this->done('Zapisano kontaktów: '.$n.'.',['tab'=>'list','type'=>'contact']);return;
        default: throw new CHttpException(400,'Nieznane działanie.');
        }
    }
    public function detailLinks(): array
    {
        $ev=new \LimeSurvey\PluginManager\PluginEvent('limeControlCrmRecordLinks');
        foreach(['teamId'=>$this->team,'recordType'=>$this->type,'recordId'=>$this->id] as $key=>$v) {$ev->set($key,$v);}
        App()->getPluginManager()->dispatchEvent($ev); $links=[];
        foreach((array)$ev->get('links') as $l) {
            $url=is_array($l)?($l['url']??null):null;
            if (is_string($url) && !str_starts_with($url,'//') && (str_starts_with($url,'/') || str_starts_with($url,'index.php')) && !preg_match('/[\x00-\x20\\\\]/',$url) && is_string($l['label']??null)) {$links[]=$l;}
        }
        return $links;
    }
    public function board(string $type,?int $owner=null): array
    {
        $args=[$this->team,$type]; $where='team_id=? AND type=?';
        if($owner!==null){$where.=' AND owner_id=?';$args[]=$owner;}
        return array_column($this->s->all('SELECT status,COUNT(*) AS total FROM '.$this->s->table('lc_crm_record').' WHERE '.$where.' GROUP BY status',$args),'total','status');
    }
    public function report(string $from,string $to): array
    {
        foreach([$from,$to] as $date){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d || $d->format('Y-m-d')!==$date){throw new CHttpException(400,'Nieprawidłowa data raportu.');}}
        if($from>$to){throw new CHttpException(400,'Początek okresu musi poprzedzać koniec.');}
        $wins=['won'=>0,'lost'=>0]; $pipeline=[];
        for($offset=0;;$offset+=500){$deals=$this->s->listing($this->team,'deal',null,'','',0,$offset,500);
            foreach($deals as $r) {
                if(isset($wins[$r['status']])) {if($r['closed_at']>=$from && $r['closed_at']<=$to){$wins[$r['status']]++;}}
                else {$cur=$r['currency'];$stage=$r['status'];$pipeline[$stage][$cur]=($pipeline[$stage][$cur]??0)+(int)round(100*(float)$r['amount']);}
            }
            if(count($deals)<500){break;}
        }
        $table=$this->s->table('lc_crm_record');
        return [$wins,$pipeline,$this->s->one("SELECT COUNT(*) AS n FROM $table WHERE team_id=? AND type='case' AND owner_id=0 AND status<>'resolved'",[$this->team])['n'],
            $this->s->one("SELECT COUNT(*) AS n FROM $table WHERE team_id=? AND type IN ('task','case') AND due<? AND status NOT IN ('done','resolved')",[$this->team,date('Y-m-d')])['n']];
    }
}
