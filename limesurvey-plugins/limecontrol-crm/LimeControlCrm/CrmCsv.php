<?php
// SPDX-License-Identifier: GPL-2.0-or-later
final class LcCrmCsv
{
    public const COLUMNS=['company_id','name','email','phone','tags'];
    public static function preview(LcCrmStore $s,int $team,string $csv): array
    {
        if ($csv==='' || strlen($csv)>524288 || !mb_check_encoding($csv,'UTF-8')) {throw new DomainException('Użyj CSV UTF-8 do 512 KiB.');}
        $f=fopen('php://temp','w+b'); fwrite($f,preg_replace('/^\xEF\xBB\xBF/','',$csv)); rewind($f);
        try {
            if (fgetcsv($f,0,',','"','')!==self::COLUMNS) {throw new DomainException('Oczekiwane nagłówki: '.implode(',',self::COLUMNS));}
            $rows=[]; $seen=[];
            while(($fields=fgetcsv($f,0,',','"',''))!==false) {
                if ($fields===[null]) {continue;}
                if (count($fields)!==5 || count($rows)>=500) {throw new DomainException('Import przyjmuje do 500 poprawnych wierszy po pięć pól.');}
                [$company,$name,$email,$phone,$tags]=array_map('trim',$fields);
                if ($company!=='' && (!ctype_digit($company) || ($company!=='0' && ($s->get($team,(int)$company)['type']??'')!=='company'))) {throw new DomainException('Nieznany numer firmy w tym zespole.');}
                if (!$name || mb_strlen($name)>180 || !filter_var($email,FILTER_VALIDATE_EMAIL) || max(mb_strlen($phone),mb_strlen($tags),mb_strlen($email))>240) {throw new DomainException('Wiersz '.(count($rows)+2).' zawiera nieprawidłowe dane kontaktowe.');}
                $fingerprint=(int)$company.':'.strtolower($email);
                if (isset($seen[$fingerprint])) {throw new DomainException('Ten sam kontakt występuje dwukrotnie w pliku. Połącz wiersze przed importem.');}
                $seen[$fingerprint]=true; $existing=$s->lookupContact($team,(int)$company,$email);
                $rows[]=['values'=>['parent_id'=>(int)$company,'title'=>$name,'email'=>$email,'phone'=>$phone,'tags'=>$tags],
                    'existing_id'=>$existing['id']??0,'revision'=>$existing['revision']??0];
            }
            if (!$rows) {throw new DomainException('Brak wierszy do importu.');}
            return $rows;
        } finally {fclose($f);}
    }
    public static function apply(LcCrmStore $s,int $team,int $actor,array $rows,array $merge): int
    {
        return $s->atomic(function() use($s,$team,$actor,$rows,$merge) {
            $n=0;
            foreach($rows as $i=>$row) {
                $v=$row['values']; $old=$s->lookupContact($team,(int)$v['parent_id'],$v['email']);
                if (($old['id']??0)!==$row['existing_id'] || ($old['revision']??0)!==$row['revision']) {throw new DomainException('Kontakty zmieniły się. Wykonaj podgląd ponownie.');}
                if ($old && empty($merge[$i])) {continue;}
                if ($old) {$v=array_filter($v,static fn($x)=>$x!=='')+$old;}
                $s->save($team,'contact',$v,$actor,$old); $n++;
            }
            return $n;
        });
    }
    public static function cell($value): string
    {
        $v=(string)$value;
        return preg_match('/^(?:[\t\r\n]|[\x00-\x20\x{FEFF}]*[=+@-])/u',$v) ? "'".$v : $v;
    }
    public static function export(LcCrmStore $s,int $team,$stream): void
    {
        fputcsv($stream,self::COLUMNS,',','"','');
        for ($offset=0;;$offset+=500) {
            $rows=$s->listing($team,'contact',null,'','',0,$offset,500);
            foreach($rows as $r) {fputcsv($stream,array_map([self::class,'cell'],[$r['parent_id']?:'',$r['title'],$r['email'],$r['phone'],$r['tags']]),',','"','');}
            if (count($rows)<500) {break;}
        }
    }
}
