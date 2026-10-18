<?php

declare(strict_types=1);
namespace MyInvoice\Action\Accounting\Closing;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Http\SessionOnlyAccountingAct;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Tax\Nonprofit\{NonprofitLegalProfileService,NonprofitTaxSavingsService,NonprofitDppoDataProvider,NonprofitTaxAssessmentException};
use MyInvoice\Service\Tax\Return\{AccountingDecreeService,TaxReturnException};
use MyInvoice\Infrastructure\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class NonprofitDppoAction
{
    use AccountingActionSupport;
    public function __construct(private readonly NonprofitLegalProfileService $profiles,private readonly NonprofitTaxSavingsService $savings,
        private readonly NonprofitDppoDataProvider $provider,private readonly Connection $db) {}

    public function profile(Request $q,Response $r,array $args):Response
    {
        return$this->handle($q,$r,false,function(int$s)use($q,$args):array{
            $result=$this->profiles->read($s,$this->id($args,'id'));$caps=$this->capabilities($q)+['native_dppo'=>false,'filing_ready'=>false,'preparation_findings'=>[]];
            try{if((new AccountingDecreeService($this->db))->assertDppoSupported($s,(int)$result['period']['fiscal_year'])!=='504')throw new TaxReturnException('nonprofit_legal_profile_framework_required','DPPO 504 vyžaduje stejný účetní rámec v celém období.',422);$caps['native_dppo']=true;$this->provider->gather($s,(int)$result['period']['fiscal_year']);$caps['filing_ready']=true;}
            catch(TaxReturnException|NonprofitTaxAssessmentException $e){$caps['preparation_findings'][]=['code'=>$e->errorCode,'message'=>$e->getMessage()];}
            return$result+['capabilities'=>$caps];
        });
    }
    public function confirmProfile(Request $q,Response $r,array $args):Response
    {return$this->handle($q,$r,true,fn(int$s):array=>$this->profiles->confirm($s,$this->id($args,'id'),$this->body($q),$this->userId($q)??0));}
    public function savings(Request $q,Response $r,array $args=[]):Response
    {return$this->handle($q,$r,false,fn(int$s):array=>$this->savings->context($s)+['capabilities'=>$this->capabilities($q)]);}
    public function previewUse(Request $q,Response $r,array $args):Response
    {return$this->handle($q,$r,false,fn(int$s):array=>$this->savings->previewUse($s,$this->id($args,'id'),$this->body($q)));}
    public function confirmUse(Request $q,Response $r,array $args):Response
    {
        return$this->handle($q,$r,true,function(int$s)use($q,$args):array{
            $b=$this->body($q);$this->fields($b,['input','preview_hash','expected_head_hash','idempotency_key']);if(!is_array($b['input']??null))$this->invalid();
            return$this->savings->confirmUse($s,$this->id($args,'id'),$b['input'],$this->text($b,'preview_hash'),$this->text($b,'expected_head_hash'),$this->text($b,'idempotency_key'),$this->userId($q)??0);
        });
    }
    public function previewReverse(Request $q,Response $r,array $args):Response
    {return$this->handle($q,$r,false,function(int$s)use($q,$args):array{$b=$this->body($q);$this->fields($b,['reason']);return$this->savings->previewReverse($s,$this->id($args,'id'),$this->id($args,'event'),$this->text($b,'reason'));});}
    public function confirmReverse(Request $q,Response $r,array $args):Response
    {
        return$this->handle($q,$r,true,function(int$s)use($q,$args):array{
            $b=$this->body($q);$this->fields($b,['reason','preview_hash','expected_head_hash','idempotency_key']);return$this->savings->confirmReverse($s,$this->id($args,'id'),$this->id($args,'event'),$this->text($b,'reason'),$this->text($b,'preview_hash'),$this->text($b,'expected_head_hash'),$this->text($b,'idempotency_key'),$this->userId($q)??0);
        });
    }
    private function capabilities(Request $q):array
    {return['can_confirm'=>RequestAuthorization::isSessionAuth($q)&&RequestAuthorization::allows($q,'accounting.periods.close',AccessLevel::WRITE),'session_only'=>true,'can_edit_reduction'=>RequestAuthorization::isSessionAuth($q)&&RequestAuthorization::allows($q,'reports',AccessLevel::WRITE)];}
    private function handle(Request $q,Response $r,bool $write,callable $work):Response
    {
        if($write&&($deny=SessionOnlyAccountingAct::deny($q,$r,'Potvrzení právního profilu nebo použití daňové úspory'))!==null)return$deny;
        $allowed=$write?$this->requireClose($q,$r,$error):$this->requirePermission($q,$r,'accounting',AccessLevel::READ,$error);if(!$allowed)return$error;
        try{return Json::ok($r,$work($this->currentSupplierId($q)));}catch(TaxReturnException|NonprofitTaxAssessmentException|PostingException $e){return Json::error($r,$e->errorCode,$e->getMessage(),$e->httpStatus);}
    }
    private function body(Request $q):array
    {$b=$q->getParsedBody();if(!is_array($b))$this->invalid();return$b;}
    private function fields(array $b,array $allowed):void
    {if(array_diff(array_keys($b),$allowed)!==[])$this->invalid();}
    private function text(array $b,string $key):string
    {if(!is_string($b[$key]??null))$this->invalid();return$b[$key];}
    private function id(array $a,string $key):int
    {$v=$a[$key]??null;if((!is_int($v)&&!is_string($v))||preg_match('/^[1-9][0-9]{0,17}$/D',(string)$v)!==1)$this->invalid();return(int)$v;}
    private function invalid():never
    {throw new TaxReturnException('nonprofit_dppo_request_invalid','Požadavek musí obsahovat platné strukturované podklady a identifikátory.',422);}
}
