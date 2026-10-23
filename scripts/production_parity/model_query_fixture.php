<?php
/** Compare old/new model SQL with mocked connections. Never executes SQL or prints queries. */
$root='/home/mytemr/web/accounting-back.myt-emr.com/public_html/';$release=rtrim($argv[1],'/').'/';
ob_start();
try {
    chdir($root);define('FCPATH',$root);require $root.'app/Config/Paths.php';$paths=new Config\Paths();
    require $paths->systemDirectory.'/Config/DotEnv.php';(new CodeIgniter\Config\DotEnv($root))->load();
    require $paths->systemDirectory.'/Boot.php';CodeIgniter\Boot::preload($paths);
    require_once SYSTEMPATH.'Common.php';require_once APPPATH.'Common.php';
    class QueryFixtureResult {
        public function getResultArray(){return [];}
        public function getResult(){return [];}
        public function getRowArray(){return [];}
        public function getRow(){return null;}
        public function getNumRows(){return 0;}
    }
    class QueryFixtureConnection {
        public $queries=[];public $DBDebug=false;
        public function query($sql,$binds=[]){$this->queries[]=[$sql,$binds];return new QueryFixtureResult();}
        public function escapeString($value){return addslashes((string)$value);}
        public function escape($value){return "'".addslashes((string)$value)."'";}
    }
    $checks=[];$queries=0;
    foreach(['Wallet','Patient_emr','Booking_doctors_fee'] as $model){
        $instances=[];$connections=[];
        foreach(['before','after'] as $variant){
            $source=file_get_contents($release.'prod/model_literals/'.$variant.'/'.$model.'.php');
            $name='Parity'.str_replace('_','',$model).ucfirst($variant);
            $source=preg_replace('/\bclass\s+'.preg_quote($model,'/').'\b/','class '.$name,$source,1);
            $file=$release.'prod/model_literals/'.$name.'.private.php';file_put_contents($file,$source);
            require $file;$class='App\\Models\\'.$name;$connections[$variant]=new QueryFixtureConnection();$instances[$variant]=new $class($connections[$variant]);
        }
        $reflection=new ReflectionClass($instances['before']);
        foreach($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method){
            if($method->getDeclaringClass()->getName()!==$reflection->getName()){continue;}
            $arguments=[];
            foreach($method->getParameters() as $parameter){
                $parameter_name=$parameter->getName();
                if ($parameter->hasType() && (string)$parameter->getType()==='array') { $arguments[]=[1]; continue; }
                $arguments[]=$parameter->isDefaultValueAvailable()?$parameter->getDefaultValue():(str_contains($parameter_name,'name')?'Fixture':1);
            }
            foreach($instances as $variant=>$instance){$connections[$variant]->queries=[];$instance->{$method->getName()}(...$arguments);}
            $checks[$model.'::'.$method->getName()]=$connections['before']->queries===$connections['after']->queries;
            $queries+=count($connections['before']->queries);
        }
    }
    $ok=!in_array(false,$checks,true)&&$queries>0;
    ob_end_clean();echo json_encode(['ok'=>$ok,'methods'=>$checks,'compared_queries'=>$queries,'executed_queries'=>0]),PHP_EOL;exit($ok?0:1);
}catch(Throwable $error){ob_end_clean();echo json_encode(['ok'=>false,'type'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()]),PHP_EOL;exit(1);}
