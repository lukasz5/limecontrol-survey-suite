<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class DeskStore
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function install(): void
    {
        $schemas = [
            'lcdesk_offers'=>['team_id'=>'integer NOT NULL','deal_id'=>'integer NOT NULL','revision'=>'integer NOT NULL','title'=>'string NOT NULL','customer'=>'string NOT NULL','items'=>'text NOT NULL','currency'=>'string(3) NOT NULL','decision'=>'string(12) NOT NULL','author'=>'integer NOT NULL','created_at'=>'string(24) NOT NULL','decided_at'=>'string(24)','decided_by'=>'integer','PRIMARY KEY (team_id,deal_id,revision)'],
            'lcdesk_mail'=>['team_id'=>'integer NOT NULL','mail_key'=>'string(64) NOT NULL','subject'=>'string NOT NULL','sender'=>'string NOT NULL','body'=>'text NOT NULL','received_at'=>'string(24) NOT NULL','case_id'=>'integer','PRIMARY KEY (team_id,mail_key)'],
            'lcdesk_feeds'=>['team_id'=>'integer NOT NULL','uid'=>'integer NOT NULL','token_hash'=>'string(64) NOT NULL UNIQUE','PRIMARY KEY (team_id,uid)'],
        ];
        foreach ($schemas as $name => $schema) {
            if (!$this->db->schema->getTable($this->db->tablePrefix.$name, true)) {
                $this->db->createCommand()->createTable('{{'.$name.'}}', $schema);
            }
        }
    }

    public function offers(int $team, int $deal): array
    {
        return $this->db->createCommand()->from('{{lcdesk_offers}}')->where(
            'team_id=:t AND deal_id=:d',[':t'=>$team,':d'=>$deal])->order('revision DESC')->queryAll();
    }

    public function offer(int $team, int $deal, int $revision): array
    {
        $row = $this->db->createCommand()->from('{{lcdesk_offers}}')->where(
            'team_id=:t AND deal_id=:d AND revision=:v',[':t'=>$team,':d'=>$deal,':v'=>$revision])->queryRow();
        if (!$row) throw new CHttpException(404);
        return $row;
    }

    public function saveOffer(int $team, int $deal, int $previous, string $title, string $customer, string $currency, array $items, int $uid): void
    {
        if (trim($title)==='' || mb_strlen($title)>200 || trim($customer)==='' || mb_strlen($customer)>200 || !in_array($currency,['PLN','EUR','USD','GBP'],true)) {
            throw new InvalidArgumentException('Check the title, customer and currency');
        }
        $latest = $this->db->createCommand()->select('MAX(revision)')->from('{{lcdesk_offers}}')
            ->where('team_id=:t AND deal_id=:d',[':t'=>$team,':d'=>$deal])->queryScalar();
        if ((int)$latest !== $previous || $previous < 0) throw new CHttpException(409, 'Reload the current revision');
        try {
            $this->db->createCommand()->insert('{{lcdesk_offers}}',[
                'team_id'=>$team,'deal_id'=>$deal,'revision'=>$previous+1,'title'=>$title,'customer'=>$customer,
                'items'=>json_encode($items,JSON_THROW_ON_ERROR),'currency'=>$currency,'decision'=>'pending','author'=>$uid,'created_at'=>gmdate('Y-m-d H:i:s')
            ]);
        } catch (CDbException $e) {
            if ($this->offers($team,$deal) && (int)$this->offers($team,$deal)[0]['revision'] > $previous) throw new CHttpException(409,'Reload the current revision');
            throw $e;
        }
    }

    public function decide(int $team, int $deal, int $version, string $decision, int $uid): void
    {
        if (!in_array($decision,['accepted','declined'],true)) throw new InvalidArgumentException('Invalid decision');
        $updated = $this->db->createCommand()->update('{{lcdesk_offers}}',
            ['decision'=>$decision,'decided_by'=>$uid,'decided_at'=>gmdate('Y-m-d H:i:s')],
            "team_id=:t AND deal_id=:d AND revision=:v AND decision='pending'",[':t'=>$team,':d'=>$deal,':v'=>$version]);
        if (!$updated) throw new CHttpException(409,'Decision already recorded, or revision unavailable');
    }

    public function feedKey(int $team,int $uid,bool $revoke): ?string
    {
        $tx = $this->db->beginTransaction();
        try {
            $this->db->createCommand()->delete('{{lcdesk_feeds}}','team_id=:t AND uid=:u',[':t'=>$team,':u'=>$uid]);
            $key = null;
            if (!$revoke) {
                $key = bin2hex(random_bytes(32));
                $this->db->createCommand()->insert('{{lcdesk_feeds}}',['team_id'=>$team,'uid'=>$uid,'token_hash'=>hash('sha256',$key)]);
            }
            $tx->commit();
            return $key;
        } catch (Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }

    public function feed(string $key): array
    {
        if (!preg_match('/^[0-9a-f]{64}$/D',$key)) throw new CHttpException(404);
        $row = $this->db->createCommand()->from('{{lcdesk_feeds}}')->where('token_hash=:h',[':h'=>hash('sha256',$key)])->queryRow();
        if (!$row) throw new CHttpException(404);
        return $row;
    }

    public function mailExists(int $team,string $key): bool
    {
        return (bool)$this->db->createCommand()->select('mail_key')->from('{{lcdesk_mail}}')
            ->where('team_id=:t AND mail_key=:k',[':t'=>$team,':k'=>$key])->queryScalar();
    }

    public function addMail(int $team,string $key,array $message): void
    {
        try {
            $this->db->createCommand()->insert('{{lcdesk_mail}}',$message + ['team_id'=>$team,'mail_key'=>$key]);
        } catch (CDbException $e) {
            if (!$this->mailExists($team,$key)) throw $e;
        }
    }

    public function messages(int $team,int $case,bool $includeUnassigned,int $offset=0,int $limit=26): array
    {
        $query=$this->db->createCommand()->from('{{lcdesk_mail}}')->where('team_id=:t',[':t'=>$team]);
        $query->andWhere($includeUnassigned ? '(case_id=:c OR case_id IS NULL)' : 'case_id=:c', [':c'=>$case]);
        return $query->order('received_at DESC, mail_key')->limit($limit,$offset)->queryAll();
    }

    public function assignMail(int $team,string $key,int $case): void
    {
        $n=$this->db->createCommand()->update('{{lcdesk_mail}}',['case_id'=>$case],
            'team_id=:t AND mail_key=:k AND case_id IS NULL',[':t'=>$team,':k'=>$key]);
        if (!$n) throw new CHttpException(409,'Message is already assigned or unavailable');
    }
}
