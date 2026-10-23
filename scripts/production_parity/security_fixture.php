<?php
/** Synthetic authorization/login fixture. No application models, notifications or external writes. */
function is_cli(): bool { return empty($GLOBALS['fixture_web']); }
function model(string $name, bool $getShared = true, &$connection = null) {
    return $GLOBALS['fixture_models'][substr($name, strrpos($name, '\\') + 1)];
}
$root = rtrim($argv[1], '/') . '/';
ob_start();
try {
    chdir($root); define('FCPATH', $root);
    require $root . 'app/Config/Paths.php'; $paths = new Config\Paths();
    require $paths->systemDirectory . '/Config/DotEnv.php'; (new CodeIgniter\Config\DotEnv($root))->load();
    require $paths->systemDirectory . '/Boot.php'; CodeIgniter\Boot::preload($paths);
    require_once SYSTEMPATH . 'Common.php'; require_once APPPATH . 'Common.php';
    date_default_timezone_set('Asia/Manila');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    class FixtureStore {
        public $data; public $selects = 0; public $updates = 0; public $writes = [];
        public function select($columns, $where, $limit) {
            $this->selects++;
            if (isset($where['token']) && ($where['token'] !== 'fixture-token' || (int) $where['id'] !== 10)) { return null; }
            return $this->data;
        }
        public function insert($values) { $this->writes[] = $values; return 1; }
        public function update($where, $values) { $this->updates++; return true; }
        public function get_details_by_email($email) { return $email === 'fixture@example.invalid' ? $this->data : false; }
    }
    class FixtureRouter {
        public $method = 'get';
        public function methodName() { return $this->method; }
        public function controllerName() { return 'App\\Controllers\\Fixture'; }
    }
    class FixtureController extends App\Core\MYT_Controller {
        public $entered = false;
        public function configure($request, $user = null) {
            $this->request = $request; $this->response = new CodeIgniter\HTTP\Response(config('App'));
            $this->payload = ['requester' => 10]; $this->router = new FixtureRouter();
            $this->api_log = new FixtureStore(); $this->api_response = new FixtureStore();
            $this->requester_user = $user;
        }
        public function authenticate() { return $this->_api_validation(); }
        public function permission() { return $this->_permission_allowed('get'); }
        public function admin() { return $this->_require_admin(); }
        public function self_access($id) { return $this->_require_self_or_admin($id); }
        public function branch_ids() { return $this->_requester_branch_ids(); }
        public function cron() { return $this->_cron_authorized(); }
        public function get() { $this->entered = true; return true; }
    }
    class FixtureThrottle {
        public $attempts = 0;
        public function check($key, $capacity, $seconds) { return ++$this->attempts <= $capacity; }
    }
    class FixtureLogin extends App\Controllers\Login {
        public function configure($request, $user) {
            $this->request=$request; $this->response=new CodeIgniter\HTTP\Response(config('App'));
            $this->router=new FixtureRouter(); $this->router->method='index';
            $this->user=$user; $this->api_response=new FixtureStore(); $this->api_log_id=1;
        }
        public function attempt() { return $this->_attempt_login(); }
        protected function _register_token($id) { return ['token'=>'fixture-issued','token_expiry'=>'2099-01-01']; }
        protected function _add_user_log($user, $type, $response) { return true; }
    }
    $make_request = static function ($headers = [], $payload = []) {
        $request = new CodeIgniter\HTTP\IncomingRequest(config('App'),new CodeIgniter\HTTP\URI('https://accounting-back.myt-emr.com/'),null,new CodeIgniter\HTTP\UserAgent());
        $request->setMethod('GET'); $request->setGlobal('request', array_merge(['requester'=>10,'token'=>'fixture-token'],$payload));
        foreach (array_merge(['API-KEY'=>API_KEY],$headers) as $name=>$value) { $request->setHeader($name,$value); }
        return $request;
    };
    $user = new FixtureStore(); $page = new FixtureStore(); $permission = new FixtureStore();
    $person = (object) ['id'=>10,'role_id'=>19,'token'=>'fixture-token','token_expiry'=>date('Y-m-d H:i:s',time()+3600),'branch_id'=>'1,2'];
    $user->data=$person; $page->data=(object)['id'=>1]; $permission->data=(object)['is_allowed'=>1];
    $GLOBALS['fixture_models']=['User'=>$user,'Page'=>$page,'Permission'=>$permission]; $GLOBALS['fixture_web']=true;
    $controller=new FixtureController(); $controller->configure($make_request());
    $checks=['valid_token'=>$controller->authenticate()===true,'permission_allowed'=>$controller->permission()===true,
        'branch_ids'=>$controller->branch_ids()===[1,2],'self_allowed'=>$controller->self_access(10)===true];
    $checks['other_user_denied']=$controller->self_access(11)->getStatusCode()===401;
    $checks['non_admin_denied']=$controller->admin()->getStatusCode()===401;
    $permission->data=(object)['is_allowed'=>0];
    $checks['permission_denied']=$controller->permission()->getStatusCode()===401;
    $checks['remap_stops_denied']=$controller->_remap('get')->getStatusCode()===401 && !$controller->entered;
    $page->data=null; $checks['missing_page_allowed']=$controller->permission()===true;
    $page->data=(object)['id'=>1]; $permission->data=null; $checks['missing_permission_allowed']=$controller->permission()===true;
    $person->role_id=ROLE_ADMIN; $permission->data=(object)['is_allowed'=>0];
    $checks['admin_bypass']=$controller->admin()===true && $controller->permission()===true;
    $person->role_id=19;
    $controller->configure($make_request(['API-KEY'=>'invalid-fixture'])); $checks['invalid_key']=$controller->authenticate()->getStatusCode()===401;
    $controller->configure($make_request([],['token'=>'invalid-fixture'])); $checks['invalid_token']=$controller->authenticate()->getStatusCode()===401;
    $person->token_expiry=date('Y-m-d H:i:s',time()-60); $controller->configure($make_request());
    $checks['expired_token']=$controller->authenticate()->getStatusCode()===401; $person->token_expiry=date('Y-m-d H:i:s',time()+3600);
    $controller->configure($make_request(['Origin-API-Key'=>'invalid-fixture'])); $checks['invalid_origin_key']=$controller->authenticate()->getStatusCode()===401;
    $origin_keys=explode(',',(string)myt_env('CSR_API_KEYS',CSR_API_KEY));
    if (trim($origin_keys[0])!=='') {
        $count=$user->selects; $controller->configure($make_request(['Origin-API-Key'=>trim($origin_keys[0])],['token'=>'invalid-fixture']));
        $checks['csr_compatibility']=$controller->authenticate()===true && $user->selects===$count;
    }
    $controller->configure($make_request(['X-Cron-Secret'=>'invalid-fixture'])); $checks['cron_denied']=$controller->cron()->getStatusCode()===401;
    $controller->configure($make_request(['X-Cron-Secret'=>CRON_SECRET])); $checks['cron_header']=$controller->cron()===true;
    $GLOBALS['fixture_web']=false; $checks['cron_cli']=$controller->cron()===true; $GLOBALS['fixture_web']=true;
    $throttle=new FixtureThrottle(); Config\Services::injectMock('throttler',$throttle);
    $login_user=new FixtureStore(); $login_user->data=['id'=>10,'password'=>password_hash('FixturePassword123!',PASSWORD_BCRYPT),'token'=>'fixture','token_expiry'=>'2099-01-01'];
    $login=new FixtureLogin();
    $login->configure($make_request([],['email'=>'fixture@example.invalid','password'=>'FixturePassword123!']),$login_user);
    $success=$login->attempt(); $body=json_decode($success->getBody(),true);
    $checks['login_success']=$success->getStatusCode()===200 && !isset($body['password']);
    $checks['rehash_mocked']=$login_user->updates===1;
    $messages=[];
    foreach ([['email'=>'unknown@example.invalid','password'=>'wrong'],['email'=>'fixture@example.invalid','password'=>'wrong']] as $payload) {
        $login->configure($make_request([],$payload),$login_user); $messages[]=json_decode($login->attempt()->getBody(),true)['messages'];
    }
    $checks['uniform_login_error']=$messages[0]===$messages[1];
    for ($attempt=0;$attempt<3;$attempt++) { $login->configure($make_request([],['email'=>'unknown@example.invalid','password'=>'wrong']),$login_user);$last=$login->attempt(); }
    $checks['login_throttle']=$last->getStatusCode()===429;
    $ok=!in_array(false,$checks,true);
    ob_end_clean();echo json_encode(['ok'=>$ok,'checks'=>$checks,'business_writes'=>false,'smtp_calls'=>0]),PHP_EOL;exit($ok?0:1);
} catch (Throwable $error) {
    ob_end_clean();echo json_encode(['ok'=>false,'type'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()]),PHP_EOL;exit(1);
}
