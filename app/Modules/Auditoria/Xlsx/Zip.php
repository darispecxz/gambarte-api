<?php

namespace App\Modules\Auditoria\Xlsx;

class Zip
{
    public static function build($files)
    {
        $local   = '';
        $central = '';
        $offset  = 0;

        foreach ($files as $name => $data) {
            $crc    = crc32($data);
            $unclen = strlen($data);
            $comp   = gzdeflate($data, 6);
            if ($comp === false) {
                $comp   = $data;
                $method = 0;
            } else {
                $method = 8;
            }
            $clen = strlen($comp);
            $name = str_replace('\\', '/', $name);

            $lf  = "\x50\x4b\x03\x04";
            $lf .= pack('v', 20);
            $lf .= pack('v', 0);
            $lf .= pack('v', $method);
            $lf .= pack('v', 0);
            $lf .= pack('v', 0);
            $lf .= pack('V', $crc);
            $lf .= pack('V', $clen);
            $lf .= pack('V', $unclen);
            $lf .= pack('v', strlen($name));
            $lf .= pack('v', 0);
            $lf .= $name;

            $local .= $lf . $comp;

            $cf  = "\x50\x4b\x01\x02";
            $cf .= pack('v', 20);
            $cf .= pack('v', 20);
            $cf .= pack('v', 0);
            $cf .= pack('v', $method);
            $cf .= pack('v', 0);
            $cf .= pack('v', 0);
            $cf .= pack('V', $crc);
            $cf .= pack('V', $clen);
            $cf .= pack('V', $unclen);
            $cf .= pack('v', strlen($name));
            $cf .= pack('v', 0);
            $cf .= pack('v', 0);
            $cf .= pack('v', 0);
            $cf .= pack('v', 0);
            $cf .= pack('V', 0);
            $cf .= pack('V', $offset);
            $cf .= $name;

            $central .= $cf;
            $offset  += strlen($lf) + $clen;
        }

        $eocd  = "\x50\x4b\x05\x06";
        $eocd .= pack('v', 0);
        $eocd .= pack('v', 0);
        $eocd .= pack('v', count($files));
        $eocd .= pack('v', count($files));
        $eocd .= pack('V', strlen($central));
        $eocd .= pack('V', strlen($local));
        $eocd .= pack('v', 0);

        return $local . $central . $eocd;
    }
}
