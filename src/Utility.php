<?php
namespace NxMultiTool;

final class Utility
{
    public static function passwords(array $input): array
    {
        $length = Access::integer($input, 'length', 4, 64, 20); $count = Access::integer($input, 'count', 1, 8, 4);
        $pools = [];
        foreach (['upper' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'lower' => 'abcdefghijklmnopqrstuvwxyz', 'digit' => '0123456789', 'symbols' => '!@#$%^&*()-_=+[]{}:,.?'] as $key => $pool) {
            if (($input[$key] ?? '') === '1') { $pools[] = $pool; }
        }
        if (!$pools) { throw new \InvalidArgumentException('文字種を選択してください。'); }
        $all = implode('', $pools); $results = [];
        for ($n = 0; $n < $count; $n++) {
            $chars = []; foreach ($pools as $pool) { $chars[] = $pool[random_int(0, strlen($pool) - 1)]; }
            while (count($chars) < $length) { $chars[] = $all[random_int(0, strlen($all) - 1)]; }
            for ($i = count($chars) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]]; }
            $results[] = implode('', $chars);
        }
        return $results;
    }
    public static function bcrypt(array $input): array
    {
        $plain = Access::text($input, 'plain', 72);
        if ($plain === '') { throw new \InvalidArgumentException('平文を入力してください。'); }
        if (($input['mode'] ?? '') === 'verify') {
            $hash = Access::text($input, 'hash', 60);
            if (!preg_match('/^\$2[aby]\$(0[4-9]|1[0-2])\$[.\/A-Za-z0-9]{53}$/D', $hash)) { throw new \InvalidArgumentException('cost 12以下のbcryptハッシュを入力してください。'); }
            return ['match' => password_verify($plain, $hash)];
        }
        $cost = Access::integer($input, 'cost', ($input['test_mode'] ?? '') === '1' ? 4 : 10, 12, 10);
        return ['hash' => password_hash($plain, PASSWORD_BCRYPT, ['cost' => $cost])];
    }
}
