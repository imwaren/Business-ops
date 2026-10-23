<?php

use Config\Services;

function encrypt_id($id)
{
    $encryption = Services::encrypter();
    return base64_encode($encryption->encrypt($id));
}

function encrypt_object($data)
{
    $encryption = Services::encrypter();
    $serializedData = json_encode($data); // Convert array/object to JSON string
    return base64_encode($encryption->encrypt($serializedData)); // Encrypt JSON
}

function decrypt_id($encrypted_id)
{
    $encryption = Services::encrypter();
    return $encryption->decrypt(base64_decode($encrypted_id));
}
?>