<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Leitor simples de .xlsx (primeira aba), sem dependências: o arquivo é um zip com XML.
 * Devolve linhas como arrays associativos pelo cabeçalho da linha $linhaCabecalho (1 = primeira).
 * Datas do Excel vêm como número de série; use LeitorXlsx::data() para converter.
 */
class LeitorXlsx
{
    /** @return iterable<int, array<string, string|null>> */
    public static function linhas(string $arquivo, int $linhaCabecalho = 1): iterable
    {
        $zip = new ZipArchive;
        if ($zip->open($arquivo) !== true) {
            throw new RuntimeException("Não consegui abrir {$arquivo}. Ele precisa estar no formato .xlsx (abra no Excel ou LibreOffice e salve como .xlsx).");
        }
        $textos = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sst = simplexml_load_string($xml);
            foreach ($sst->si as $si) {
                $textos[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
            }
        }
        $aba = self::primeiraAba($zip);
        $xml = $zip->getFromName($aba);
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException("A planilha {$arquivo} não tem abas.");
        }
        $sheet = simplexml_load_string($xml);
        $cab = null;
        foreach ($sheet->sheetData->row as $row) {
            $n = (int) $row['r'];
            $valores = [];
            foreach ($row->c as $c) {
                $col = self::coluna(preg_replace('/\d+/', '', (string) $c['r']));
                $t = (string) $c['t'];
                $v = match ($t) {
                    's' => $textos[(int) $c->v] ?? null,
                    'inlineStr' => (string) $c->is->t,
                    default => isset($c->v) ? (string) $c->v : null,
                };
                $valores[$col] = $v === null ? null : trim($v);
            }
            if ($n < $linhaCabecalho) {
                continue;
            }
            if ($n === $linhaCabecalho) {
                $cab = $valores;

                continue;
            }
            if ($cab === null || ! array_filter($valores, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }
            $linha = [];
            foreach ($cab as $i => $nome) {
                $linha[(string) $nome] = $valores[$i] ?? null;
            }
            yield $n => $linha;
        }
    }

    /** Número de série do Excel ou "dd/mm/aaaa" → "aaaa-mm-dd". */
    public static function data(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $v, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            return substr($v, 0, 10);
        }
        if (is_numeric($v)) {
            return gmdate('Y-m-d', (int) round(((float) $v - 25569) * 86400));
        }

        return null;
    }

    private static function primeiraAba(ZipArchive $zip): string
    {
        $wb = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $rels = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        if ($wb && $rels) {
            $id = (string) $wb->sheets->sheet[0]->attributes('r', true)['id'];
            foreach ($rels->Relationship as $r) {
                if ((string) $r['Id'] === $id) {
                    return 'xl/'.ltrim(str_replace('/xl/', '', (string) $r['Target']), '/');
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function coluna(string $letras): int
    {
        $n = 0;
        foreach (str_split($letras) as $l) {
            $n = $n * 26 + (ord($l) - 64);
        }

        return $n - 1;
    }
}
