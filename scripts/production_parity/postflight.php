<?php
/** Private production checks: no business DML or live job execution. */
ob_start();
try {
    $root='/home/mytemr/web/accounting-back.myt-emr.com/public_html/';
    $release=rtrim($argv[1],'/').'/';chdir($root);
    require $root.'vendor/codeigniter4/framework/system/Config/DotEnv.php';
    (new CodeIgniter\Config\DotEnv($root))->load();
    require $root.'vendor/codeigniter4/framework/system/Test/bootstrap.php';
    $before=unserialize(file_get_contents($release.'prod/production.config.private'),['allowed_classes'=>false]);
    $keys=['DB_USERNAME','DB_PASSWORD','MASTER_DB','MOBILE_DB','OAKRIDGE_DB','MAC_DB','CONSO_DB','MANTLEWOOD_DB','WAREHOUSE_DB','PHARMACY_DB','REPORT_DB',
        'OAKRIDGE_EMR_DB','MAC_EMR_DB','CONSO_EMR_DB','MANTLEWOOD_EMR_DB','MOBILE_EMR_DB','ENCRYPTION_KEY','API_KEY','CSR_API_KEY','MYT_EMAIL','MYT_PASSWORD','MYT_NAME',
        'OUTLOOK_EMAIL','OUTLOOK_EMAIL_PASSWORD','VM4_ENDPOINT','VM4_SECRET_TOKEN','DICOM_API_KEY','DICOM_UPLOAD_WORKLIST_URL','DICOM_STATION_AET','FRONT_END_URL'];
    $equal=[];
    foreach($keys as $key){if(array_key_exists($key,$before['constants'])){$equal[$key]=defined($key)&&(string)constant($key)===(string)$before['constants'][$key];}}
    $payload=json_decode(file_get_contents($release.'prod/payload.private.json'),true);
    foreach(['key'=>'PAYLOAD_AES_KEY','iv'=>'PAYLOAD_AES_IV'] as $field=>$key){$equal[$key]=isset($payload[$field])&&hash_equals($payload[$field],(string)myt_env($key,''));}
    $row=Config\Database::connect('default')->query('SELECT COUNT(*) AS total, SUM(CASE WHEN is_allowed=0 THEN 1 ELSE 0 END) AS denied FROM permission')->getRowArray();
    $ok=!in_array(false,$equal,true);
    ob_end_clean();echo json_encode(['ok'=>$ok,'configuration_values_preserved'=>$equal,'existing_permission_rows'=>(int)$row['total'],'existing_denied_rows'=>(int)$row['denied']]),PHP_EOL;exit($ok?0:1);
}catch(Throwable $error){ob_end_clean();echo json_encode(['ok'=>false,'type'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()]),PHP_EOL;exit(1);}
