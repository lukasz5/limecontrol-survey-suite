<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class DeskDocuments
{
    /** All prices are stored in minor units. Each revision keeps its original labels and prices. */
    public static function lineItems(array $names, array $quantities, array $prices): array
    {
        if (count($names) > 100) {
            throw new InvalidArgumentException('Maximum 100 items');
        }
        $items = [];
        foreach ($names as $i => $rawName) {
            $name = trim((string)$rawName);
            $quantity = trim((string)($quantities[$i] ?? ''));
            $price = str_replace(',', '.', trim((string)($prices[$i] ?? '')));
            if ($name === '' && $quantity === '' && $price === '') {
                continue;
            }
            if ($name === '' || mb_strlen($name) > 180 || !preg_match('/^[1-9]\d{0,4}$/D', $quantity)
                || !preg_match('/^(0|[1-9]\d{0,6})(\.\d{1,2})?$/D', $price)) {
                throw new InvalidArgumentException('Invalid offer line');
            }
            $parts = explode('.', $price, 2);
            $minor = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
            $items[] = ['name' => $name, 'quantity' => (int)$quantity, 'price' => $minor];
        }
        if (!$items) {
            throw new InvalidArgumentException('Add at least one item');
        }
        return $items;
    }

    public static function money(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }

    public static function pdf(array $offer, bool $polish): string
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(17, 18, 17);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->SetTitle(($polish ? 'Oferta' : 'Offer').' #'.$offer['deal_id'].'/'.$offer['revision']);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', 'B', 18);
        $pdf->MultiCell(0, 11, ($polish ? 'Oferta' : 'Offer').' #'.$offer['deal_id'].'/'.$offer['revision'],0,'L');
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->MultiCell(0, 7, $offer['customer'].' · '.substr($offer['created_at'],0,10),0,'L');
        $pdf->MultiCell(0, 7, $offer['title'],0,'L');
        $pdf->Ln(4);
        $pdf->SetFont('dejavusans', '', 10);
        $items = json_decode($offer['items'], true, 32, JSON_THROW_ON_ERROR);
        $total = 0;
        $e = static fn($text) => htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows = '';
        foreach ($items as $item) {
            $line = $item['quantity'] * $item['price'];
            $total += $line;
            $rows .= '<tr><td width="55%">'.$e($item['name']).'</td><td width="10%" align="right">'.$item['quantity'].'</td><td width="17%" align="right">'.self::money($item['price']).'</td><td width="18%" align="right">'.self::money($line).'</td></tr>';
        }
        $labels = $polish ? ['Pozycja', 'Ilość', 'Cena', 'Wartość'] : ['Item', 'Qty', 'Unit price', 'Amount'];
        $pdf->writeHTML('<table cellpadding="5"><thead><tr bgcolor="#e9edf0"><th width="55%">'.$labels[0].'</th><th width="10%" align="right">'.$labels[1].'</th><th width="17%" align="right">'.$labels[2].'</th><th width="18%" align="right">'.$labels[3].'</th></tr></thead>'.$rows.'</table>');
        $pdf->SetFont('dejavusans','B',12);
        $pdf->MultiCell(0, 9, ($polish ? 'Razem' : 'Total').'   '.self::money($total).' '.$offer['currency'], 0, 'R');
        return $pdf->Output('offer.pdf', 'S');
    }

    public static function calendar(array $tasks, string $namespace, string $name): string
    {
        $escape = static fn($s) => str_replace(["\\", "\r\n", "\r", "\n", ';', ','], ["\\\\", '\n', '\n', '\n', '\;', '\,'], (string)$s);
        $lines = ['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//LimeControl//CRM Tasks//EN','CALSCALE:GREGORIAN','X-WR-CALNAME:'.$escape($name)];
        foreach ($tasks as $task) {
            $due = DateTimeImmutable::createFromFormat('!Y-m-d', $task['due'] ?? '', new DateTimeZone('UTC'));
            if (!$due || $due->format('Y-m-d') !== ($task['due'] ?? '')) {
                continue;
            }
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$task['id'].'-'.$namespace.'@limecontrol.local';
            $lines[] = 'DTSTAMP:'.gmdate('Ymd\THis\Z');
            $lines[] = 'DTSTART;VALUE=DATE:'.$due->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$due->modify('+1 day')->format('Ymd');
            $lines[] = 'SUMMARY:'.$escape($task['title']);
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        $folded = [];
        foreach ($lines as $line) {
            while (strlen($line) > 75) {
                $head = mb_strcut($line, 0, 75, 'UTF-8');
                $folded[] = $head;
                $line = ' '.substr($line, strlen($head));
            }
            $folded[] = $line;
        }
        return implode("\r\n", $folded)."\r\n";
    }
}
