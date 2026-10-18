<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\{AuthMiddleware,CsrfMiddleware,SupplierScopeMiddleware,TenantDomainMiddleware};
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\Closing\SecuritiesDebt504UseGuard;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\{ServerRequestFactory,ResponseFactory};
final class SecuritiesDebt504HttpTest extends TestCase
{
    private SecuritiesDebt504WorkflowTest $fixture;
    private Connection $db;
    private \Slim\App $app;
    private int $supplier;
    private int $actor;
    protected function setUp(): void
    {
        $this->fixture=new SecuritiesDebt504WorkflowTest('testNativeCouponAcquisitionAccrualAcrossYearPartialReceiptsAndPrincipalAreNotIncome');(new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture);
        foreach(['db','supplier','actor'] as $key)$this->$key=(new \ReflectionProperty($this->fixture,$key))->getValue($this->fixture);
        $c=Bootstrap::buildContainer();$this->app=\Slim\Factory\AppFactory::create(new ResponseFactory(),$c);\MyInvoice\Routes\SecuritiesDebt504Routes::register($this->app);$this->app->add($c->get(CsrfMiddleware::class));
    }
    protected function tearDown(): void{(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);}
    private function http(string $method,string $path,array $body=[],string $auth='session',bool $csrf=true,array $permissions=['accounting'=>1,'accounting.journal.post'=>2],?int $supplier=null,array $query=[]): array
    {
        $q=(new ServerRequestFactory())->createServerRequest($method,'/api/accounting/securities/debt'.$path)->withParsedBody($body)->withQueryParams($query)->withAttribute(AuthMiddleware::ATTR_METHOD,$auth)->withAttribute(AuthMiddleware::ATTR_USER,['id'=>$this->actor,'role'=>'accountant'])->withAttribute(AuthMiddleware::ATTR_SESSION,['csrf_token'=>'synthetic-debt-csrf'])->withAttribute('auth.effective_role',new EffectiveRole(99,'Synthetic debt role','staff',true,$permissions))->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$supplier??$this->supplier)->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT,new \MyInvoice\Service\Tenant\TenantDomainContext('canonical','example.test','https://example.test'))->withHeader('Origin','https://example.test');if($csrf)$q=$q->withHeader('X-CSRF-Token','synthetic-debt-csrf');$r=$this->app->handle($q);return[$r->getStatusCode(),json_decode((string)$r->getBody(),true,flags:JSON_THROW_ON_ERROR)];
    }
    public function testSessionPermissionCsrfAndFailClosedSharedMutationGateAreEnforcedWithoutDml(): void
    {
        $terms=(new \ReflectionMethod($this->fixture,'coupon'))->invoke($this->fixture);$source=(new \ReflectionMethod($this->fixture,'acquisition'))->invoke($this->fixture,$terms);$command=['operation'=>'register','terms'=>$terms]+$source;
        $counts=fn()=>(new \ReflectionMethod($this->fixture,'countRows'))->invoke($this->fixture);$before=$counts();
        foreach([['bearer',true,['accounting'=>1,'accounting.journal.post'=>2]],['session',false,['accounting'=>1,'accounting.journal.post'=>2]],['session',true,['accounting'=>1]]] as [$auth,$csrf,$permissions]){[$status]=$this->http('POST','/preview',['command'=>$command],$auth,$csrf,$permissions);self::assertSame(403,$status);self::assertSame($before,$counts());}
        [$status,$page]=$this->http('GET','');self::assertSame(200,$status);self::assertSame(SecuritiesDebt504UseGuard::integrated(),$page['financial_enabled']);
        [$status,$preview]=$this->http('POST','/preview',['command'=>$command]);self::assertSame(SecuritiesDebt504UseGuard::integrated()?200:409,$status,json_encode($preview));if(!SecuritiesDebt504UseGuard::integrated())self::assertSame('debt_mutation_guard_unavailable',$preview['error']['code']);self::assertSame($before,$counts());
        [$status,$choices]=$this->http('GET','/profile-choices',query:['date'=>'2025-12-31']);self::assertSame(200,$status);self::assertCount(1,$choices['periods']);
        [$status,$sources]=$this->http('GET','/sources/acquisitions',query:['date'=>$terms['acquired_on']]);self::assertSame(200,$status);self::assertContains((string)$source['line_id'],array_map('strval',array_column($sources['rows'],'line_id')));
        [$status,$wrongDate]=$this->http('GET','/sources/acquisitions',query:['date'=>'2025-12-31']);self::assertSame(200,$status);self::assertSame([],$wrongDate['rows']);
        (new \ReflectionMethod($this->fixture,'applyCommand'))->invoke($this->fixture,$command);[$status,$claimed]=$this->http('GET','/sources/acquisitions',query:['date'=>$terms['acquired_on']]);self::assertSame(200,$status);self::assertSame([],$claimed['rows']);
        [$status,$other]=$this->http('GET','/sources/acquisitions',supplier:$this->supplier+999999,query:['date'=>'2025-12-31']);self::assertSame(200,$status);self::assertSame([],$other['rows']);
    }
}
