<?php
// SPDX-License-Identifier: GPL-2.0-or-later

/** Stores business keys outside the native response and participant tables. */
final class FeedbackStorage
{
    private $db;
    private $prefix;
    private $driver;
    private $contactKey;

    public function __construct(PDO $db, $prefix, $contactKey)
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix) || strlen($contactKey) < 32) {
            throw new RuntimeException('Invalid feedback storage configuration');
        }
        $this->db = $db;
        $this->prefix = $prefix . 'lcf_';
        $this->driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->contactKey = $contactKey;
    }

    private function execute($sql, array $params = [])
    {
        $statement = $this->db->prepare(str_replace('@', $this->prefix, $sql));
        $statement->execute($params);
        return $statement;
    }

    public function install()
    {
        $text = $this->driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT';
        $tables = [
            'orders' => "id CHAR(64) PRIMARY KEY, store VARCHAR(80) NOT NULL, rev BIGINT NOT NULL, eligible BIGINT NOT NULL, updated BIGINT NOT NULL, body $text NOT NULL",
            'events' => 'id CHAR(64) PRIMARY KEY, order_key CHAR(64) NOT NULL, rev BIGINT NOT NULL, created BIGINT NOT NULL',
            'jobs' => "id CHAR(64) PRIMARY KEY, order_key CHAR(64) NOT NULL, kind VARCHAR(20) NOT NULL, sid INTEGER NOT NULL, state VARCHAR(16) NOT NULL, due BIGINT NOT NULL, retry_at BIGINT NOT NULL, token_id INTEGER NOT NULL, selected $text NOT NULL, recipient CHAR(64) NOT NULL, sent_at BIGINT NOT NULL",
            'contacts' => 'id CHAR(64) PRIMARY KEY, blocked INTEGER NOT NULL, last_sent BIGINT NOT NULL',
            'facts' => "id CHAR(64) PRIMARY KEY, sid INTEGER NOT NULL, store VARCHAR(80) NOT NULL, report $text NOT NULL, created BIGINT NOT NULL",
            'cases' => "id CHAR(64) PRIMARY KEY, sid INTEGER NOT NULL, store VARCHAR(80) NOT NULL, payload $text NOT NULL, handled INTEGER NOT NULL, created BIGINT NOT NULL, last_try BIGINT NOT NULL DEFAULT 0, stage VARCHAR(12) NOT NULL DEFAULT 'open'",
        ];
        foreach ($tables as $table => $schema) {
            $this->execute("CREATE TABLE IF NOT EXISTS @$table ($schema)");
        }
        $columns = $this->driver === 'sqlite'
            ? array_column($this->execute('PRAGMA table_info(@cases)')->fetchAll(PDO::FETCH_ASSOC),'name')
            : $this->execute('SELECT column_name FROM information_schema.columns WHERE table_schema='.
                ($this->driver === 'mysql' ? 'DATABASE()' : 'current_schema()').' AND table_name=?',[$this->prefix.'cases'])->fetchAll(PDO::FETCH_COLUMN);
        foreach (['stage'=>"VARCHAR(12) NOT NULL DEFAULT 'open'",'last_try'=>'BIGINT NOT NULL DEFAULT 0'] as $field=>$type) {
            if (!in_array($field,$columns,true)) { $this->execute("ALTER TABLE @cases ADD COLUMN $field $type"); }
        }
        foreach (['jobs'=>['state, due, retry_at','sid, token_id'], 'cases'=>['handled, created'], 'facts'=>['sid, store'], 'orders'=>['updated'], 'events'=>['created']] as $table=>$indexes) {
            foreach ($indexes as $i=>$columns) {
                $index = $this->prefix.$table.'_i'.$i;
                if ($this->driver === 'mysql') {
                    if ($this->execute('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',[$this->prefix.$table,$index])->fetchColumn()) { continue; }
                }
                $if = $this->driver === 'mysql' ? '' : 'IF NOT EXISTS ';
                $this->execute("CREATE INDEX $if $index ON @$table ($columns)");
            }
        }
    }

    private function insertOnce($table, array $row)
    {
        $suffix = $this->driver === 'mysql' ? 'ON DUPLICATE KEY UPDATE id=id' : 'ON CONFLICT (id) DO NOTHING';
        $fields = implode(',',array_keys($row));
        $marks = implode(',',array_fill(0,count($row),'?'));
        return $this->execute("INSERT INTO @$table ($fields) VALUES ($marks) $suffix",array_values($row))->rowCount();
    }

    private function lock($table, $id)
    {
        $suffix = $this->driver === 'sqlite' ? '' : ' FOR UPDATE';
        return $this->execute("SELECT * FROM @$table WHERE id=?".$suffix,[$id])->fetch(PDO::FETCH_ASSOC);
    }

    public function transaction(callable $work)
    {
        $this->db->beginTransaction();
        try { $result = $work(); $this->db->commit(); return $result; }
        catch (Throwable $error) { $this->db->rollBack(); throw $error; }
    }

    private function digest(...$parts)
    {
        return hash('sha256',json_encode($parts, JSON_THROW_ON_ERROR));
    }

    public function recipient($store, $email)
    {
        return hash_hmac('sha256', $store . '|' . strtolower(trim($email)), $this->contactKey);
    }

    private function lockContact($id)
    {
        $this->insertOnce('contacts',['id'=>$id,'blocked'=>0,'last_sent'=>0]);
        return $this->lock('contacts',$id);
    }

    public function unsubscribe($recipient)
    {
        $this->transaction(function () use ($recipient) {
            $this->lockContact($recipient);
            $this->execute('UPDATE @contacts SET blocked=1 WHERE id=?',[$recipient]);
        });
    }

    public function accept($store, array $data, array $campaigns, $now)
    {
        $order = $this->digest($store,$data['order_id']);
        return $this->transaction(function () use ($store,$order,$data,$campaigns,$now) {
            $this->insertOnce('orders',['id'=>$order,'store'=>$store,'rev'=>0,'eligible'=>0,'updated'=>$now,'body'=>'{}']);
            $old = $this->lock('orders',$order);
            $event = $this->digest($store,$data['event_id']);
            $this->insertOnce('events',['id'=>$event,'order_key'=>$order,'rev'=>$data['revision'],'created'=>$now]);
            $record = $this->lock('events',$event);
            if ($record['order_key'] !== $order || (int)$record['rev'] !== $data['revision']) {
                throw new InvalidArgumentException('Event id reused for different revision');
            }
            if ($data['revision'] <= (int)$old['rev']) {
                return $data['revision'] === (int)$old['rev'] ? 'duplicate' : 'stale';
            }
            $first = $data['status'] === 'eligible' ? ((int)$old['eligible'] ?: $data['occurred_at']) : 0;
            $this->execute('UPDATE @orders SET rev=?, eligible=?, updated=?, body=? WHERE id=?',
                [$data['revision'],$first,$now,json_encode($data,JSON_THROW_ON_ERROR),$order]);
            foreach ($campaigns as $kind=>$campaign) {
                $id = $this->digest($order,$kind);
                $anchor = FeedbackProtocol::anchor($kind,$data,$first);
                $this->insertOnce('jobs',['id'=>$id,'order_key'=>$order,'kind'=>$kind,'sid'=>$campaign['survey'],
                    'state'=>'pending','due'=>0,'retry_at'=>0,'token_id'=>0,'selected'=>'[]','recipient'=>'','sent_at'=>0]);
                $this->execute("UPDATE @jobs SET state=?,sid=?,due=?,retry_at=0 WHERE id=? AND state IN ('pending','hold')",
                    [$anchor ? 'pending' : 'hold',$campaign['survey'],$anchor + $campaign['delayHours']*3600,$id]);
            }
            return 'accepted';
        });
    }

    public function due($now, $limit = 100)
    {
        return $this->execute("SELECT j.id,j.kind,j.sid,o.store,o.body FROM @jobs j JOIN @orders o ON o.id=j.order_key
            WHERE j.state='pending' AND j.due<=? AND j.retry_at<=? ORDER BY j.due,j.id LIMIT ".(int)$limit,[$now,$now])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function defer($id, $now)
    {
        $this->execute("UPDATE @jobs SET retry_at=? WHERE id=? AND state='pending'",[$now+300,$id]);
    }

    /** Claim commits before the external SMTP call. A crash produces an auditable uncertain delivery, never a blind resend. */
    public function claim($id, array $checked, array $campaign, $now, callable $makeToken)
    {
        $orderKey = $this->execute('SELECT order_key FROM @jobs WHERE id=?',[$id])->fetchColumn();
        if (!$orderKey) { return null; }
        return $this->transaction(function () use ($id,$orderKey,$checked,$campaign,$now,$makeToken) {
            $order = $this->lock('orders',$orderKey);
            $job = $this->lock('jobs',$id);
            if ($job['state'] !== 'pending' || $job['due'] > $now || $job['retry_at'] > $now || (int)$job['sid'] !== $campaign['survey']
                || (int)$order['rev'] !== $checked['revision']) { return null; }
            $current = json_decode($order['body'],true,16,JSON_THROW_ON_ERROR);
            // A same-revision preflight mismatch indicates a broken store sequence. Fail closed.
            foreach (['status','email','customer_ref','items','returned_items','delivered_at','return_closed_at'] as $key) {
                if ($current[$key] !== $checked[$key]) { throw new RuntimeException('Inconsistent store snapshot'); }
            }
            $recipient = $this->recipient($order['store'],$current['email']);
            $person = $this->lockContact($recipient);
            if ($person['blocked'] || ((int)$person['last_sent'] && (int)$person['last_sent'] + $campaign['frequencyHours']*3600 > $now)) {
                $this->execute("UPDATE @jobs SET state='suppressed' WHERE id=?",[$id]);
                return null;
            }
            $items = $job['kind'] === 'returns' ? $current['returned_items'] : $current['items'];
            $selected = array_slice($items,0,5);
            $newToken = $makeToken((int)$job['sid'],$current['email']);
            if ($newToken === null) {
                $this->execute("UPDATE @jobs SET state='suppressed' WHERE id=?",[$id]);
                return null;
            }
            $tid = (int)$newToken;
            if ($tid < 1) { throw new RuntimeException('Participant creation failed'); }
            $this->execute("UPDATE @jobs SET state='sending', token_id=?, recipient=?, selected=?, sent_at=? WHERE id=?",
                [$tid,$recipient,json_encode(['items'=>$selected,'total'=>count($items)],JSON_THROW_ON_ERROR),$now,$id]);
            $this->execute('UPDATE @contacts SET last_sent=? WHERE id=?',[$now,$recipient]);
            return ['tid'=>$tid,'recipient'=>$recipient,'email'=>$current['email'],'sid'=>(int)$job['sid']];
        });
    }

    public function markSent($id)
    {
        $this->execute("UPDATE @jobs SET state='sent' WHERE id=? AND state='sending'",[$id]);
    }

    public function context($sid, $tid)
    {
        $row = $this->execute("SELECT j.selected,j.recipient,j.kind,o.store,o.body FROM @jobs j JOIN @orders o ON o.id=j.order_key
            WHERE j.sid=? AND j.token_id=? AND j.state IN ('sending','sent')",[$sid,$tid])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return null; }
        $saved = json_decode($row['selected'],true,12,JSON_THROW_ON_ERROR);
        $row['selected'] = $saved['items'] ?? $saved;
        $row['totalItems'] = $saved['total'] ?? count($row['selected']);
        $row['body'] = json_decode($row['body'],true,16,JSON_THROW_ON_ERROR);
        return $row;
    }

    public function saveObservation($sid, $rid, $store, array $report, array $cases, $now)
    {
        $this->transaction(function () use ($sid,$rid,$store,$report,$cases,$now) {
            $id = $this->digest($store,$sid,$rid);
            $this->insertOnce('facts',['id'=>$id,'sid'=>$sid,'store'=>$store,'report'=>json_encode($report,JSON_THROW_ON_ERROR),'created'=>$now]);
            foreach ($cases as $case) {
                $case['caseKey'] = $this->digest('case',$id);
                $this->insertOnce('cases',['id'=>$case['caseKey'],'sid'=>$sid,'store'=>$store,'payload'=>json_encode($case,JSON_THROW_ON_ERROR),'handled'=>0,'created'=>$now]);
            }
        });
    }

    public function facts($sid, $store)
    {
        $rows = $this->execute('SELECT report FROM @facts WHERE sid=? AND store=?',[$sid,$store]);
        while (($raw=$rows->fetchColumn()) !== false) { yield json_decode($raw,true,12,JSON_THROW_ON_ERROR); }
    }

    public function pendingCases()
    {
        return $this->execute('SELECT id,payload FROM @cases WHERE handled=0 ORDER BY last_try,created,id LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markAttempt($id, $now)
    {
        $this->execute('UPDATE @cases SET last_try=? WHERE id=?',[$now,$id]);
    }

    public function cases($sid, $store, $page)
    {
        return $this->execute("SELECT id,payload,stage,handled FROM @cases WHERE sid=? AND store=? ORDER BY created DESC,id LIMIT 51 OFFSET ".(50*$page),[$sid,$store])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setStage($sid, $store, $id, $stage)
    {
        if (!in_array($stage,['open','working','closed'],true)) { throw new InvalidArgumentException('Invalid case stage'); }
        $this->execute('UPDATE @cases SET stage=? WHERE id=? AND sid=? AND store=?',[$stage,$id,$sid,$store]);
    }

    public function markHandled($id)
    {
        $this->execute('UPDATE @cases SET handled=1 WHERE id=?',[$id]);
    }

    public function operationalCounts($sid,$store)
    {
        return $this->execute('SELECT j.state,COUNT(*) AS count FROM @jobs j JOIN @orders o ON o.id=j.order_key WHERE j.sid=? AND o.store=? GROUP BY j.state',
            [$sid,$store])->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function prune($now, $days)
    {
        $before = $now - $days*86400;
        $this->transaction(function () use ($before) {
            // Keep business keys/revisions and sent markers so a replay cannot recreate a campaign.
            $this->execute("UPDATE @jobs SET selected='[]' WHERE order_key IN (SELECT id FROM @orders WHERE updated<?)",[$before]);
            $this->execute("UPDATE @jobs SET state='expired' WHERE state IN ('pending','hold') AND order_key IN (SELECT id FROM @orders WHERE updated<?)",[$before]);
            $this->execute("UPDATE @orders SET body='{}' WHERE updated<?",[$before]);
            $this->execute('DELETE FROM @cases WHERE created<?',[$before]);
            $this->execute('DELETE FROM @facts WHERE created<?',[$before]);
            $this->execute('DELETE FROM @events WHERE created<?',[$before]);
        });
    }
}
