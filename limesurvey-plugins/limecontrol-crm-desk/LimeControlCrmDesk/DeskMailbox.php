<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class DeskMailbox
{
    private static function header($part,string $name): string
    {
        $headers=$part->getHeaders();
        $value=$headers[strtolower($name)] ?? '';
        return is_array($value) ? implode(', ', $value) : (string)$value;
    }

    private static function plain($part,int $depth=0): string
    {
        if ($depth>12 || stripos(self::header($part,'content-disposition'),'attachment')!==false) return '';
        if ($part->isMultipart()) {
            for ($i=1; $i<=$part->countParts(); $i++) {
                $text=self::plain($part->getPart($i),$depth+1);
                if ($text!=='') return $text;
            }
            return '';
        }
        $type=self::header($part,'content-type');
        if ($type!=='' && stripos($type,'text/plain')!==0) return '';
        $body=$part->getContent();
        switch (strtolower(trim(self::header($part,'content-transfer-encoding')))) {
            case 'base64': $body=base64_decode($body,true) ?: ''; break;
            case 'quoted-printable': $body=quoted_printable_decode($body); break;
        }
        if (preg_match('/charset="?([\w.-]+)/i',$type,$match)) {
            try { $body=mb_convert_encoding($body,'UTF-8',$match[1]); } catch (ValueError $e) { /* fall back to substitution */ }
        }
        return mb_strcut(mb_scrub(str_replace("\0",'',$body),'UTF-8'),0,48000,'UTF-8');
    }

    private static function importMessage(DeskStore $store,int $team,string $key,string $raw,int $timestamp): void
    {
        $mail=new Zend_Mail_Message(['raw'=>$raw]);
        $decode=static function ($s) {
            $decoded=iconv_mime_decode($s,ICONV_MIME_DECODE_CONTINUE_ON_ERROR,'UTF-8');
            return mb_strcut(mb_scrub($decoded ?: $s,'UTF-8'),0,240,'UTF-8');
        };
        $store->addMail($team,$key,[
            'subject'=>$decode(self::header($mail,'subject')), 'sender'=>$decode(self::header($mail,'from')),
            'received_at'=>gmdate('Y-m-d H:i:s',$timestamp), 'body'=>self::plain($mail),
        ]);
    }

    /** Use TLS and IMAP EXAMINE. The caller persists progress after each processed UID. */
    public static function syncImap(DeskStore $store,int $team,array $account,callable $getCursor,callable $setCursor): int
    {
        $host=$account['host']??'';
        $user=$account['username']??'';
        $env=$account['passwordEnv']??'';
        $folder=$account['folder']??'INBOX';
        $port=(int)($account['port']??993);
        if (!is_string($host) || !preg_match('/^[a-z0-9.-]+$/Di',$host) || !$user || !is_string($folder)
            || !preg_match('/^[A-Z][A-Z0-9_]+$/D',$env) || !($password=getenv($env)) || $port<1 || $port>65535) {
            throw new InvalidArgumentException('Invalid IMAP configuration');
        }
        $imap=new Zend_Mail_Protocol_Imap($host,$port,'SSL');
        try {
            if (!$imap->login($user,$password)) throw new RuntimeException('IMAP authentication failed');
            $state=$imap->examine($folder);
            if (empty($state['uidvalidity'])) throw new RuntimeException('IMAP mailbox unavailable');
            $source=hash('sha256',json_encode([$team,$host,$port,$user,$folder,$state['uidvalidity']],JSON_THROW_ON_ERROR));
            $cursor=(int)$getCursor($source);
            $ids=$imap->search(['UID',($cursor+1).':*']);
            if ($ids===false) throw new RuntimeException('IMAP search failed');
            $added=0;
            foreach (array_slice($ids,0,100) as $seq) {
                $meta=$imap->fetch(['UID','RFC822.SIZE'],(int)$seq);
                $uid=(int)($meta['UID']??0);
                if ($uid<=$cursor) continue;
                $key=hash('sha256',$source.'|'.$uid);
                if (isset($meta['RFC822.SIZE']) && $meta['RFC822.SIZE']<=4*1024*1024 && !$store->mailExists($team,$key)) {
                    $message=$imap->fetch(['UID','RFC822','INTERNALDATE'],(int)$seq);
                    if ((int)($message['UID']??0)!==$uid || !isset($message['RFC822'])) break;
                    self::importMessage($store,$team,$key,$message['RFC822'],strtotime($message['INTERNALDATE']??'') ?: time());
                    $added++;
                }
                $setCursor($source,$uid);
                $cursor=$uid;
            }
            return $added;
        } finally {
            $imap->logout();
        }
    }

    /** A dedicated Maildir for each team can be delivered locally or mirrored using an IMAP client. */
    public static function sync(DeskStore $store,int $team,string $directory): int
    {
        if ($team<=0 || !is_dir($directory.'/new') || !is_dir($directory.'/cur')) throw new InvalidArgumentException('Invalid team or Maildir');
        $added=0;
        foreach (['new','cur'] as $subdirectory) {
            foreach (new FilesystemIterator($directory.'/'.$subdirectory) as $file) {
                if (!$file->isFile() || $file->isLink() || $file->getSize()>4*1024*1024) continue;
                // Maildir flags may change on a move between new and cur; the basename stays stable.
                $key=hash('sha256',explode(':2,',$file->getFilename(),2)[0]);
                if ($store->mailExists($team,$key)) continue;
                $raw=file_get_contents($file->getPathname());
                if ($raw===false) throw new RuntimeException('Cannot read Maildir message');
                self::importMessage($store,$team,$key,$raw,$file->getMTime());
                if (++$added>=100) return $added;
            }
        }
        return $added;
    }
}
