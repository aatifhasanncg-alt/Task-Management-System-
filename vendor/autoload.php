<?php

if (!class_exists('PHPGangsta_GoogleAuthenticator')) {

    class PHPGangsta_GoogleAuthenticator
    {
        protected $passCodeLength = 6;
        protected $secretLength = 16;

        public function createSecret($secretLength = 16)
        {
            $validChars = $this->getBase32LookupTable();
            $secret = '';
            for ($i = 0; $i < $secretLength; $i++) {
                $secret .= $validChars[array_rand($validChars)];
            }
            return $secret;
        }

        public function getCode($secret, $timeSlice = null)
        {
            if ($timeSlice === null) {
                $timeSlice = floor(time() / 30);
            }
            $secretkey = $this->base32Decode($secret);
            $time = chr(0) . chr(0) . chr(0) . chr(0) . pack('N*', $timeSlice);
            $hm = hash_hmac('SHA1', $time, $secretkey, true);
            $offset = ord(substr($hm, -1)) & 0x0F;
            $hashpart = substr($hm, $offset, 4);
            $value = unpack('N', $hashpart);
            $value = $value[1];
            $value = $value & 0x7FFFFFFF;
            $modulo = pow(10, $this->passCodeLength);
            return str_pad($value % $modulo, $this->passCodeLength, '0', STR_PAD_LEFT);
        }

        public function getQRCodeGoogleUrl($name, $secret, $title = null, $params = [])
        {
            $urlencoded = urlencode('otpauth://totp/' . urlencode($name) . '?secret=' . $secret . '');
            if ($title !== null) {
                $urlencoded .= urlencode('&issuer=' . urlencode($title));
            }
            return 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=' . $urlencoded . '';
        }

        public function verifyCode($secret, $code, $discrepancy = 1, $currentTimeSlice = null)
        {
            if ($currentTimeSlice === null) {
                $currentTimeSlice = floor(time() / 30);
            }
            if (strlen($code) != 6) {
                return false;
            }
            for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
                $calculatedCode = $this->getCode($secret, $currentTimeSlice + $i);
                if ($this->timingSafeEquals($calculatedCode, $code)) {
                    return true;
                }
            }
            return false;
        }

        protected function base32Decode($secret)
        {
            if (empty($secret))
                return '';
            $base32chars = $this->getBase32LookupTable();
            $base32charsFlipped = array_flip($base32chars);
            $paddingCharCount = substr_count($secret, $base32chars[32]);
            $allowedValues = [6, 4, 3, 1, 0];
            if (!in_array($paddingCharCount, $allowedValues))
                return false;
            for ($i = 0; $i < 4; $i++) {
                if (
                    $paddingCharCount == $allowedValues[$i] &&
                    substr($secret, -($allowedValues[$i])) != str_repeat($base32chars[32], $allowedValues[$i])
                )
                    return false;
            }
            $secret = str_replace('=', '', $secret);
            $secret = str_split($secret);
            $binaryString = '';
            for ($i = 0; $i < count($secret); $i += 8) {
                $x = '';
                if (!in_array($secret[$i], $base32chars))
                    return false;
                for ($j = 0; $j < 8; $j++) {
                    $x .= str_pad(base_convert(@$base32charsFlipped[@$secret[$i + $j]], 10, 2), 5, '0', STR_PAD_LEFT);
                }
                $eightBits = str_split($x, 8);
                for ($z = 0; $z < count($eightBits); $z++) {
                    $binaryString .= (($y = chr(base_convert($eightBits[$z], 2, 10))) || ord($y) == 48) ? $y : '';
                }
            }
            return $binaryString;
        }

        protected function getBase32LookupTable()
        {
            return [
                'A',
                'B',
                'C',
                'D',
                'E',
                'F',
                'G',
                'H',
                'I',
                'J',
                'K',
                'L',
                'M',
                'N',
                'O',
                'P',
                'Q',
                'R',
                'S',
                'T',
                'U',
                'V',
                'W',
                'X',
                'Y',
                'Z',
                '2',
                '3',
                '4',
                '5',
                '6',
                '7',
                '='
            ];
        }

        protected function timingSafeEquals($safeString, $userString)
        {
            if (function_exists('hash_equals')) {
                return hash_equals($safeString, $userString);
            }
            $safeLen = strlen($safeString);
            $userLen = strlen($userString);
            if ($userLen != $safeLen)
                return false;
            $result = 0;
            for ($i = 0; $i < $userLen; $i++) {
                $result |= (ord($safeString[$i]) ^ ord($userString[$i]));
            }
            return $result === 0;
        }
    }
}