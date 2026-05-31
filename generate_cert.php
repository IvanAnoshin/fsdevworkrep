<?php
// Генерируем самоподписанный сертификат
$dn = [
    "commonName" => "localhost",
    "organizationName" => "Friendscape",
    "countryName" => "RU"
];

$privkey = openssl_pkey_new([
    "private_key_bits" => 2048,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
]);

$csr = openssl_csr_new($dn, $privkey);
$sscert = openssl_csr_sign($csr, null, $privkey, 365);

openssl_pkey_export_to_file($privkey, __DIR__ . '/key.pem');
openssl_x509_export_to_file($sscert, __DIR__ . '/cert.pem');

echo "cert.pem и key.pem сгенерированы успешно!";