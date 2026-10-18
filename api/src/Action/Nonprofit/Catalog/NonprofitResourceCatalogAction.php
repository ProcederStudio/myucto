<?php

declare(strict_types=1);
namespace MyInvoice\Action\Nonprofit\Catalog;
use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Security\{AccessLevel,RequestAuthorization};
use MyInvoice\Service\Nonprofit\Catalog\NonprofitResourceCatalog;
use MyInvoice\Service\Nonprofit\Capital\CapitalException;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Accounting\{PostingException,Dimension\DimensionException};
use Psr\Http\Message\{ServerRequestInterface as Request,ResponseInterface as Response};

final class NonprofitResourceCatalogAction
{
    use AccountingActionSupport;
    public function __construct(private readonly NonprofitResourceCatalog $catalog) {}
    public function funding(Request $request,Response $response,array $args=[]): Response{return $this->read($request,$response,$args,false);}
    public function capital(Request $request,Response $response,array $args=[]): Response{return $this->read($request,$response,$args,true);}
    public function events(Request $request,Response $response,array $args=[]): Response{return $this->read($request,$response,$args,true,true);}
    private function read(Request $request,Response $response,array $args,bool $capital,bool $events=false): Response
    {
        if(!RequestAuthorization::allows($request,'accounting',AccessLevel::READ))return Json::error($response,'forbidden','Pro tuto operaci nemáte oprávnění.',403);
        $q=$request->getQueryParams();
        foreach(['date','as_of','known_at']as$key)if(array_key_exists($key,$q))return Json::error($response,'nonprofit_catalog_time_unsupported','Katalog dokládá současný stav, nikoli historický okamžik.',409);
        $detail=isset($args['id'])&&!$events;$allowed=$detail?['supplier_id']:['after_id','limit','supplier_id'];
        $after=$q['after_id']??0;$limit=$q['limit']??25;
        if(array_diff(array_keys($q),$allowed)!==[]||is_bool($after)||is_bool($limit)||filter_var($after,FILTER_VALIDATE_INT)===false||filter_var($limit,FILTER_VALIDATE_INT)===false||(int)$after<0||(int)$limit<1||(int)$limit>100)return Json::error($response,'nonprofit_catalog_query_invalid','Neplatný filtr nebo stránkování.',422);
        try{
            $supplier=$this->currentSupplierId($request);$id=isset($args['id'])?(int)$args['id']:null;
            return Json::ok($response,$capital?$this->catalog->capital($supplier,(int)$after,(int)$limit,$id,$events):$this->catalog->funding($supplier,(int)$after,(int)$limit,$id));
        }catch(NonprofitTaxAssessmentException|CapitalException|PostingException|DimensionException|PaymentException $e){return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus);}
    }
}
