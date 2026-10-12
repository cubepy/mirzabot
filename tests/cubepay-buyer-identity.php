<?php
declare(strict_types=1);
namespace CubePayBuyerTest;
// Run: php tests/cubepay-buyer-identity.php (no bot config, database or network).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function testLoadFunction(string $source, string $name): void
{
    $tokens = token_get_all($source, TOKEN_PARSE);
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if (!is_array($tokens[$j] ?? null) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $name) continue;
        $code = ''; $depth = 0; $body = false;
        for ($j = $i; $j < $n; $j++) {
            $token = $tokens[$j];
            $code .= is_array($token) ? $token[1] : $token;
            if ($token === '{') { $depth++; $body = true; }
            if ($token === '}' && --$depth === 0 && $body) { eval('namespace CubePayBuyerTest; use Throwable; ' . $code); return; }
        }
    }
    throw new \RuntimeException('Production function not found: ' . $name);
}
function check(bool $condition, string $message): void
{
    if (!$condition) throw new \RuntimeException($message);
}

$state = ['profile'=>['username'=>'@Buyer_name'], 'requests'=>[], 'lookups'=>[], 'fee'=>'0', 'lookup_error'=>false];
$domainhosts = 'store.example.test/bot';
foreach (['CURLOPT_URL','CURLOPT_RETURNTRANSFER','CURLOPT_ENCODING','CURLOPT_MAXREDIRS','CURLOPT_TIMEOUT','CURLOPT_FOLLOWLOCATION','CURLOPT_HTTP_VERSION','CURL_HTTP_VERSION_1_1','CURLOPT_CUSTOMREQUEST','CURLOPT_HTTPHEADER','CURLOPT_POSTFIELDS'] as $i=>$constant) {
    if (!defined($constant)) define($constant, 1000+$i);
}
function select($table,$field,$whereField=null,$whereValue=null,$type='select') {
    if ($table==='PaySetting') return ['ValuePay'=>['apiternado'=>'fixture-token','feestatusternado'=>'onfeeternado','feeternado'=>$GLOBALS['state']['fee']][$whereValue]];
    $GLOBALS['state']['lookups'][]=[$table,$field,$whereField,$whereValue,$type];
    if ($GLOBALS['state']['lookup_error']) throw new \RuntimeException('Fixture lookup failure');
    return $GLOBALS['state']['profile'];
}
function curl_init() { return new \stdClass(); }
function curl_setopt_array($c,$options) { $c->options=$options; return true; }
function curl_setopt($c,$key,$value) { $c->options[$key]=$value; return true; }
function curl_exec($c) { $GLOBALS['state']['requests'][]=$c->options; return '{"success":true,"pay_page_url":"https://gateway.example.test/pay"}'; }
function curl_close($c) {}
$source=file_get_contents(dirname(__DIR__).'/function.php');
foreach (['cubepayFeeValue','cubepayApplyFee','cubepayPayableAmount','trnado'] as $fn) testLoadFunction($source,$fn);
$result=trnado('order-A',50000,'7000000123');
$request=end($state['requests']); $payload=json_decode($request[CURLOPT_POSTFIELDS],true);
check(($payload['customer_user_id']??null)==='7000000123','Large Telegram ID must be sent exactly');
check($payload['customer_username']==='Buyer_name','Username should be normalized');
check($state['lookups'][0]===['user','username','id','7000000123','select'],'Lookup must be scoped to same buyer');
check($payload['price_amount']===50000 && $payload['order_id']==='order-A' && $payload['callback_url']==='https://store.example.test/bot/payment/iranpay2.php' && $payload['redirect_after_payment']===false,'Existing payment fields must be preserved');
check($request[CURLOPT_URL]==='https://cubevps.ir/pay/create-order.php' && in_array('Authorization: Bearer fixture-token',$request[CURLOPT_HTTPHEADER],true),'Endpoint and token must be preserved');
check($result['success'] && $result['payment_link']==='https://gateway.example.test/pay','Response normalization must be preserved');
$state['profile']='@SecondBuyer';trnado('order-B',1,'7000000456');$payload=json_decode(end($state['requests'])[CURLOPT_POSTFIELDS],true);
check($payload['customer_user_id']==='7000000456' && $payload['customer_username']==='SecondBuyer','Consecutive buyers and scalar profile result');
foreach ([null,false,[],['username'=>'none'],['username'=>'NULL'],['username'=>'<script>']] as $profile) {
    $state['profile']=$profile;trnado('missing-name',1,123);$p=json_decode(end($state['requests'])[CURLOPT_POSTFIELDS],true);
    check($p['customer_user_id']==='123' && !isset($p['customer_username']),'Missing username must not hide numeric ID');
}
$state['lookup_error']=true;trnado('lookup-error',1,'123');$p=json_decode(end($state['requests'])[CURLOPT_POSTFIELDS],true);check($p['customer_user_id']==='123','Lookup failure must not drop ID');$state['lookup_error']=false;
foreach ([null,'','0','abc',[],true,1.5] as $id) { $n=count($state['lookups']); trnado('legacy',1,$id);$p=json_decode(end($state['requests'])[CURLOPT_POSTFIELDS],true);check(!isset($p['customer_user_id']) && count($state['lookups'])===$n,'Invalid or absent IDs must not be invented'); }
trnado('two-args',1);check(!isset(json_decode(end($state['requests'])[CURLOPT_POSTFIELDS],true)['customer_user_id']),'Legacy two-argument calls');
foreach (['25'=>62500,'1000'=>51000] as $fee=>$amount) { $state['fee']=(string)$fee;trnado('fee',50000,'123');check(json_decode(end($state['requests'])[CURLOPT_POSTFIELDS],true)['price_amount']===$amount,'Fee behavior preserved'); }
$caller=file_get_contents(dirname(__DIR__).'/index.php');
check(strpos($caller, "trnado(\$randomString, \$user['Processing_value'], \$from_id)")!==false,'Checkout must pass its buyer');
echo "PASS: buyer identity, scoped lookup, legacy calls, fees and request/response behavior.\n";

