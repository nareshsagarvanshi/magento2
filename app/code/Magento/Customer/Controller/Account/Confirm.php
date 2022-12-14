<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Customer\Controller\Account;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Controller\AbstractAccount;
use Magento\Customer\Helper\Address;
use Magento\Customer\Model\Session;
use Magento\Customer\Model\Url;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface as HttpGetActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\UrlFactory;
use Magento\Framework\Exception\StateException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Model\Logger as CustomerLogger;

/**
 * Class Confirm
 *
 * Confirm class is responsible for account confirmation flow
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Confirm extends AbstractAccount implements HttpGetActionInterface
{
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Magento\Customer\Api\AccountManagementInterface
     */
    protected $customerAccountManagement;

    /**
     * @var \Magento\Customer\Api\CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * @var \Magento\Customer\Helper\Address
     */
    protected $addressHelper;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $urlModel;

    /**
     * @var Session
     */
    protected $session;

    /**
     * @var \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory
     */
    private $cookieMetadataFactory;

    /**
     * @var \Magento\Framework\Stdlib\Cookie\PhpCookieManager
     */
    private $cookieMetadataManager;

    /**
     * @var CustomerLogger
     */
    private CustomerLogger $customerLogger;

    /**
     * @param Context $context
     * @param Session $customerSession
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param AccountManagementInterface $customerAccountManagement
     * @param CustomerRepositoryInterface $customerRepository
     * @param Address $addressHelper
     * @param UrlFactory $urlFactory
     * @param CustomerLogger|null $customerLogger
     */
    public function __construct(
        Context $context,
        Session $customerSession,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        AccountManagementInterface $customerAccountManagement,
        CustomerRepositoryInterface $customerRepository,
        Address $addressHelper,
        UrlFactory $urlFactory,
        ?CustomerLogger $customerLogger = null
    ) {
        $this->session = $customerSession;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->customerAccountManagement = $customerAccountManagement;
        $this->customerRepository = $customerRepository;
        $this->addressHelper = $addressHelper;
        $this->urlModel = $urlFactory->create();
        $this->customerLogger = $customerLogger ?? ObjectManager::getInstance()->get(CustomerLogger::class);
        parent::__construct($context);
    }

    /**
     * Retrieve cookie manager
     *
     * @return \Magento\Framework\Stdlib\Cookie\PhpCookieManager
     */
    private function getCookieManager()
    {
        if (!$this->cookieMetadataManager) {
            $this->cookieMetadataManager = \Magento\Framework\App\ObjectManager::getInstance()->get(
                \Magento\Framework\Stdlib\Cookie\PhpCookieManager::class
            );
        }
        return $this->cookieMetadataManager;
    }

    /**
     * Retrieve cookie metadata factory
     *
     * @return \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory
     */
    private function getCookieMetadataFactory()
    {
        if (!$this->cookieMetadataFactory) {
            $this->cookieMetadataFactory = \Magento\Framework\App\ObjectManager::getInstance()->get(
                \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory::class
            );
        }
        return $this->cookieMetadataFactory;
    }

    /**
     * Confirm customer account by id and confirmation key
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        if ($this->session->isLoggedIn()) {
            $resultRedirect->setPath('*/*/');
            return $resultRedirect;
        }

        $customerId = $this->getCustomerId();
        $key = $this->getRequest()->getParam('key', false);
        if (empty($customerId) || empty($key)) {
            $this->messageManager->addErrorMessage(__('Bad request.'));
            $url = $this->urlModel->getUrl('*/*/index', ['_secure' => true]);
            // phpcs:ignore Magento2.Legacy.ObsoleteResponse
            return $resultRedirect->setUrl($this->_redirect->error($url));
        }

        try {
            // log in and send greeting email
            $customerEmail = $this->customerRepository->getById($customerId)->getEmail();
            $customer = $this->customerAccountManagement->activate($customerEmail, $key);
            $successMessage = $this->getSuccessMessage();
            $this->session->setCustomerDataAsLoggedIn($customer);

            if ($this->getCookieManager()->getCookie('mage-cache-sessid')) {
                $metadata = $this->getCookieMetadataFactory()->createCookieMetadata();
                $metadata->setPath('/');
                $this->getCookieManager()->deleteCookie('mage-cache-sessid', $metadata);
            }

            if ($successMessage) {
                $this->messageManager->addSuccess($successMessage);
            }

            $resultRedirect->setUrl($this->getSuccessRedirect());
            return $resultRedirect;
        } catch (StateException $e) {
            $this->messageManager->addException($e, __('This confirmation key is invalid or has expired.'));
        } catch (\Exception $e) {
            $this->messageManager->addException($e, __('There was an error confirming the account'));
        }

        $url = $this->urlModel->getUrl('*/*/index', ['_secure' => true]);
        // phpcs:ignore Magento2.Legacy.ObsoleteResponse
        return $resultRedirect->setUrl($this->_redirect->error($url));
    }

    /**
     * Returns customer id from request
     *
     * @return int
     */
    private function getCustomerId(): int
    {
        return (int)$this->getRequest()->getParam('id', 0);
    }

    /**
     * Retrieve success message
     *
     * @return Phrase|null
     * @throws NoSuchEntityException
     */
    protected function getSuccessMessage()
    {
        if ($this->addressHelper->isVatValidationEnabled()) {
            return __(
                $this->addressHelper->getTaxCalculationAddressType() == Address::TYPE_SHIPPING
                    ? 'If you are a registered VAT customer, please click <a href="%1">here</a> to enter your '
                    .'shipping address for proper VAT calculation.'
                    :'If you are a registered VAT customer, please click <a href="%1">here</a> to enter your '
                    .'billing address for proper VAT calculation.',
                $this->urlModel->getUrl('customer/address/edit')
            );
        }

        $customerId = $this->getCustomerId();
        if ($customerId && $this->customerLogger->get($customerId)->getLastLoginAt()) {
            return null;
        }

        return __('Thank you for registering with %1.', $this->storeManager->getStore()->getFrontendName());
    }

    /**
     * Retrieve success redirect URL
     *
     * @return string
     */
    protected function getSuccessRedirect()
    {
        $backUrl = $this->getRequest()->getParam('back_url', false);
        $redirectToDashboard = $this->scopeConfig->isSetFlag(
            Url::XML_PATH_CUSTOMER_STARTUP_REDIRECT_TO_DASHBOARD,
            ScopeInterface::SCOPE_STORE
        );
        if (!$redirectToDashboard && $this->session->getBeforeAuthUrl()) {
            $successUrl = $this->session->getBeforeAuthUrl(true);
        } else {
            $successUrl = $this->urlModel->getUrl('*/*/index', ['_secure' => true]);
        }
        // phpcs:ignore Magento2.Legacy.ObsoleteResponse
        return $this->_redirect->success($backUrl ? $backUrl : $successUrl);
    }
}
