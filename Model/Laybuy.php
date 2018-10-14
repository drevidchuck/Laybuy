<?php

namespace Overdose\Laybuy\Model;

use \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Overdose\Laybuy\Model\Config as LaybuyConfig;

class Laybuy extends \Magento\Payment\Model\Method\AbstractMethod
{
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $_urlBuilder;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

    /**
     * @var \Overdose\Laybuy\Gateway\Http\LaybuyClient
     */
    protected $httpClient;

    /**
     * @var \Magento\Framework\Exception\LocalizedExceptionFactory
     */
    protected $_exception;

    /**
     * @var \Magento\Sales\Api\TransactionRepositoryInterface
     */
    protected $transactionRepository;

    /**
     * @var BuilderInterface
     */
    protected $transactionBuilder;

    /**
     * @var Logger\Logger
     */
    protected $logger;


    protected $orderSender;

    /**
     * @var \Magento\Sales\Model\Order\Config
     */
    protected $salesOrderConfig;

    /**
     * @var \Magento\Framework\DB\TransactionFactory
     */
    protected $transactionFactory;

    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $invoiceService;

    /**
     * @var string
     */
    protected $_infoBlockType = \Overdose\Laybuy\Block\Info::class;

    /**
     * @var array
     */
    protected $_supportedCurrencyCodes = ['NZD', 'AUD'];

    /**
     * @var \Magento\Framework\View\Asset\Repository
     */
    protected $_assetRepo;


    /**
     * Laybuy constructor.
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $attributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param Logger\Logger $logger
     * @param \Overdose\Laybuy\Gateway\Http\LaybuyClient $httpClient
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \Magento\Framework\DB\TransactionFactory $transactionFactory
     * @param \Magento\Sales\Model\Service\InvoiceService $invoiceService
     * @param \Magento\Sales\Model\Order\Config $salesOrderConfig
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Framework\Exception\LocalizedExceptionFactory $exception
     * @param \Magento\Sales\Api\TransactionRepositoryInterface $transaction
     * @param BuilderInterface $transBuilder
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $collection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $attributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Overdose\Laybuy\Model\Logger\Logger $logger,
        \Overdose\Laybuy\Gateway\Http\LaybuyClient $httpClient,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Framework\DB\TransactionFactory $transactionFactory,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Sales\Model\Order\Config $salesOrderConfig,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\Exception\LocalizedExceptionFactory $exception,
        \Magento\Sales\Api\TransactionRepositoryInterface $transaction,
        BuilderInterface $transBuilder,
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $collection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $attributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $collection,
            $data
        );
        $this->_assetRepo = $assetRepo;
        $this->_storeManager = $storeManager;
        $this->_urlBuilder = $urlBuilder;
        $this->_checkoutSession = $checkoutSession;
        $this->httpClient = $httpClient;
        $this->_exception = $exception;
        $this->transactionRepository = $transaction;
        $this->transactionBuilder = $transBuilder;
        $this->logger = $logger;
        $this->salesOrderConfig = $salesOrderConfig;
        $this->orderSender = $orderSender;
        $this->transactionFactory = $transactionFactory;
        $this->invoiceService = $invoiceService;
    }

    /**
     * @return string
     */
    public function getCode()
    {
        return Config::CODE;
    }

    /**
     *
     * Path to logo for checkout config
     *
     * @return string
     */
    public function getLaybuyLogoSrc()
    {
        return $this->_assetRepo->getUrl('Overdose_Laybuy::images/laybuy_logo_grape.svg');
    }

    /**
     * Should we use payment method on checkout
     *
     * @param int|null $storeId Store Id
     *
     * @return bool
     */
    public function isActive($storeId = null)
    {
        $quote = $this->_checkoutSession->getQuote();

        if( !$quote ||
            !in_array($quote->getCurrency()->getQuoteCurrencyCode(), $this->_supportedCurrencyCodes) ||
            !$this->httpClient->restClient ||
            $this->getConfigData('min_order_total') > $quote->getGrandTotal() ||
            $quote->getGrandTotal() > $this->getConfigData('max_order_total')
        ) {
            return false;
        }

        return (bool)(int)$this->getConfigData('active', $storeId);
    }

    /**
     * Retrieve information from payment configuration
     *
     * @param string           $field   Field
     * @param int|string|null| $storeId Store Id
     *
     * @return mixed
     */
    public function getConfigData($field, $storeId = null)
    {
        if ('order_place_redirect_url' === $field) {
            return true;
        }
        if (null === $storeId) {
            $storeId = $this->getStore();
        }
        $path = 'payment/' . $this->getCode() . '/' . $field;
        return $this->_scopeConfig->getValue(
            $path,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns laybuy url
     *
     * @param bool|string $guestEmail
     * @return bool|string
     */
    public function getLaybuyRedirectUrl($guestEmail = false)
    {
        $quote = $this->_checkoutSession->getQuote();

        try {
            $this->validateQuote($quote);

            if ($guestEmail) {
                $quote->setCustomerEmail($guestEmail);
                $quote->getBillingAddress()->setCustomerEmail($guestEmail);
                $quote->setCheckoutMethod('guest');
                $quote->save();
            }


            if ($this->getConfigPaymentAction() == self::ACTION_AUTHORIZE_CAPTURE) {
                $payment = $quote->getPayment();
                $payment->setMethod(LaybuyConfig::CODE);
            }

            $laybuyOrder = $this->createLaybuyOrder($quote);
            $redirectUrl = $this->httpClient->getRedirectUrl($laybuyOrder);

            return $redirectUrl;

        } catch (\Exception $e) {
            $this->logger->debug([__METHOD__ . ' ERROR LAYBUY REDIRECT '. $e->getMessage() . " " => $e->getTraceAsString()]);
        }
    }

    /**
     * Confirms laybuy order
     *
     * @param $token
     * @return bool|int
     */
    public function laybuyConfirm($token)
    {
        $laybuyOrderId = $this->httpClient->getLaybuyConfirmationOrderId($token);

        $this->logger->debug([__METHOD__ . 'LAYBUY ORDER:' => $laybuyOrderId, 'TOKEN' => $token]);

        return $laybuyOrderId;
    }

    /**
     * @param $token
     * @return bool
     */
    public function laybuyCancel($token)
    {
        $laybuyCancelResult = $this->httpClient->cancelLaybuyOrder($token);
        $this->logger->debug([__METHOD__ . 'LAYBUY CANCEL STATUS:' => $laybuyCancelResult, 'TOKEN' => $token]);

        return $laybuyCancelResult;
    }

    /**
     *
     * Cancels magento order if laybuy didnt confirm order
     *
     * @param \Magento\Sales\Model\Order $order
     * @param $token
     * @param bool $comment
     * @throws \Exception
     */
    public function cancelMagentoOrder(\Magento\Sales\Model\Order $order, $token = false, $comment = false)
    {
        if (!$order->isCanceled() &&
            $order->getState() !== \Magento\Sales\Model\Order::STATE_COMPLETE &&
            $order->getState() !== \Magento\Sales\Model\Order::STATE_CLOSED) {

            $this->beforeUpdateOrder($order);

            if ($comment) {
                $order->addStatusHistoryComment($comment);
            }

            $order->cancel();
            $order->save();

            if ($token) {
                $this->laybuyCancel($token);
            }
        }
    }

    /**
     * @param $order
     * @param $token
     * @param $txnId
     * @return bool
     */
    public function processLaybuySuccessPayment($order, $token, $txnId, $laybuyOrderId)
    {
        if ($order->getState() == \Magento\Sales\Model\Order::STATE_PROCESSING) {
            return true;
        }

        $this->beforeUpdateOrder($order);

        if ($order->canInvoice() && $this->shouldBeInvoiced($order)) {

            $this->updatePayment($order, $txnId);

            $this->createInvoiceAndUpdateOrder($order, $txnId, $laybuyOrderId);

            $this->logger->debug([__METHOD__ => 'Payment processed successfully', 'token' => $token, 'Laybuy Order Id' => $laybuyOrderId]);

            return true;
        }

        $this->logger->debug([__METHOD__ => 'Payment processed with failure', 'token' => $token, 'Laybuy Order Id' => $laybuyOrderId, 'Order Can Invoice' => $order->canInvoice(), 'Order Should be Invoiced' => $this->shouldBeInvoiced($order)]);

        return false;
    }

    public function sendOrderEmail($order)
    {
        try {
            $this->orderSender->send($order);
        } catch (\Exception $e) {
            $this->_logger->critical($e);
        }
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @param $orderId
     * @throws \Exception
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function createInvoiceAndUpdateOrder(\Magento\Sales\Model\Order $order, $orderId, $laybuyOrderId)
    {

        $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING)
            ->setStatus($this->salesOrderConfig->getStateDefaultStatus(\Magento\Sales\Model\Order::STATE_PROCESSING));

        $order->addStatusHistoryComment(__('Payment approved by Laybuy, Laybuy Order ID: ' .$laybuyOrderId));

        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
        $invoice->register();

        /** @var \Magento\Framework\DB\Transaction $transaction */
        $transaction = $this->transactionFactory->create();
        $transaction->addObject($order)
            ->addObject($invoice)
            ->addObject($invoice->getOrder())
            ->save();

        $this->logger->debug([__METHOD__ => 'Invoice created succesfully', 'Laybuy Order Id' => $laybuyOrderId]);
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @param $txnId
     */
    public function updatePayment(\Magento\Sales\Model\Order $order, $txnId)
    {
        $payment = $order->getPayment();
        $payment->setTransactionId($txnId);
        $payment->setAdditionalInformation('laybuy_order_id', $txnId);
        $payment->save();
    }

    /**
     * @param \Magento\Quote\Model\Quote $quote
     */
    protected function validateQuote(\Magento\Quote\Model\Quote $quote)
    {
        if (!$quote || !$quote->getItemsCount()  || $this->getConfigData('min_order_total') > $quote->getGrandTotal() ||
            $quote->getGrandTotal() > $this->getConfigData('max_order_total')) {
            throw new \InvalidArgumentException(__("We can't initialize checkout."));
        }
    }

    /**
     * @param \Magento\Quote\Model\Quote $quote
     * @return \stdClass
     */
    protected function createLaybuyOrder(\Magento\Quote\Model\Quote $quote)
    {
        $returnUrl = $this->_urlBuilder->getUrl('laybuy/payment/response');

        $address = $quote->getBillingAddress();
        $shAddress = $quote->getShippingAddress();

        $laybuyOrder = new \stdClass();

        $quote->reserveOrderId()->save();

        $laybuyOrder->amount = number_format($quote->getGrandTotal(), 2, '.', '');
        $laybuyOrder->currency = $quote->getCurrency()->getQuoteCurrencyCode();

        if(!in_array($laybuyOrder->currency, $this->_supportedCurrencyCodes)) {
            throw new \LogicException('Laybuy doesn\'t support your currency');
        }

        $laybuyOrder->returnUrl = $returnUrl;

        $laybuyOrder->merchantReference = $quote->getReservedOrderId();

        $laybuyOrder->customer = new \stdClass();
        $laybuyOrder->customer->firstName = $address->getFirstname() ? $address->getFirstname() : $shAddress->getFirstname();
        $laybuyOrder->customer->lastName = $address->getLastname() ? $address->getLastname() : $shAddress->getLastname();
        $laybuyOrder->customer->email = $address->getEmail();

        $phone = $address->getTelephone();

        if ($phone == '' || strlen(preg_replace('/[^0-9+]/i', '', $phone)) <= 6) {
            $phone = "00 000 000";
        }

        $laybuyOrder->customer->phone = $phone;
        $laybuyOrder->items = [];

        if (!$this->getConfigData('transfer_line_items')) {
            $laybuyOrder->items[0]              = new \stdClass();
            $laybuyOrder->items[0]->id          = 1;
            $laybuyOrder->items[0]->description = $quote->getReservedOrderId();
            $laybuyOrder->items[0]->quantity    = 1;
            $laybuyOrder->items[0]->price       = number_format($quote->getGrandTotal(), 2, '.', '');
        } else {
            $i = 0;
            $totalPrice = 0;
            $priceFix = false;
            foreach($quote->getAllVisibleItems() as $item) {
                $laybuyOrder->items[$i]              = new \stdClass();
                $laybuyOrder->items[$i]->id          = $item->getId();
                $laybuyOrder->items[$i]->description = $item->getName();
                $laybuyOrder->items[$i]->quantity    = $item->getQty();
                $laybuyOrder->items[$i]->price       = number_format($item->getPrice(), 2, '.', '');
                $totalPrice += $item->getPrice() * $item->getQty();
                $i++;
            }

            if ($totalPrice != $quote->getGrandTotal()) {
                $priceFix = ($totalPrice - $quote->getGrandTotal()) / $i;
            }

            if ($priceFix) {
               foreach ($laybuyOrder->items as $laybuyItem) {
                   $laybuyItem->price = $laybuyItem->price - ($priceFix / $laybuyItem->quantity);
               }
            }
        }

        $this->logger->debug([__METHOD__ .' CREATED ORDER' => $laybuyOrder]);

        return $laybuyOrder;
    }

    /**
     * @param $order
     * @return $this
     */
    protected function beforeUpdateOrder($order)
    {
        if ($order->isPaymentReview()) {
            $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                ->setStatus('pending_payment');
        }
        return $this;
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @return bool
     */
    protected function shouldBeInvoiced(\Magento\Sales\Model\Order $order)
    {
        if ($order->hasInvoices()) {
            return false;
        }

        if ($order->getPayment()->getMethod() !== LaybuyConfig::CODE) {
            return false;
        }

        return true;
    }
}






















