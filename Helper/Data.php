<?php
/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category  Mageplaza
 * @package   Mageplaza_CustomerApproval
 * @copyright Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license   https://www.mageplaza.com/LICENSE.txt
 */

namespace Mageplaza\CustomerApproval\Helper;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PhpCookieManager;
use Magento\Store\Model\StoreManagerInterface;
use Mageplaza\Core\Helper\AbstractData;
use Mageplaza\CustomerApproval\Model\Config\Source\AttributeOptions;
use Mageplaza\CustomerApproval\Model\Config\Source\TypeAction;

/**
 * Class Data
 *
 * @package Mageplaza\CustomerApproval\Helper
 */
class Data extends AbstractData
{
    const CONFIG_MODULE_PATH = 'mpcustomerapproval';
    const XML_PATH_EMAIL     = 'email';

    /**
     * @var HttpContext
     */
    protected $_httpContext;

    /**
     * @var Http
     */
    protected $_requestHttp;

    /**
     * @var TransportBuilder
     */
    protected $transportBuilder;

    /**
     * @var CustomerRepositoryInterface
     */
    protected $customerRepositoryInterface;

    /**
     * @var Customer
     */
    protected $customer;

    /**
     * @var CustomerFactory
     */
    protected $customerFactory;

    /**
     * @var ManagerInterface
     */
    protected $messageManager;

    /**
     * @var CookieMetadataFactory
     */
    private $cookieMetadataFactory;

    /**
     * @var PhpCookieManager
     */
    private $cookieMetadataManager;

    /**
     * Data constructor.
     *
     * @param Context $context
     * @param ObjectManagerInterface $objectManager
     * @param StoreManagerInterface $storeManager
     * @param HttpContext $httpContext
     * @param Http $requestHttp
     * @param TransportBuilder $transportBuilder
     * @param CustomerRepositoryInterface $customerRepositoryInterface
     * @param Customer $customer
     * @param CustomerFactory $customerFactory
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        Context $context,
        ObjectManagerInterface $objectManager,
        StoreManagerInterface $storeManager,
        HttpContext $httpContext,
        Http $requestHttp,
        TransportBuilder $transportBuilder,
        CustomerRepositoryInterface $customerRepositoryInterface,
        Customer $customer,
        CustomerFactory $customerFactory,
        ManagerInterface $messageManager
    ) {
        $this->_httpContext                = $httpContext;
        $this->_requestHttp                = $requestHttp;
        $this->transportBuilder            = $transportBuilder;
        $this->customerRepositoryInterface = $customerRepositoryInterface;
        $this->customer                    = $customer;
        $this->customerFactory             = $customerFactory;
        $this->messageManager              = $messageManager;
        parent::__construct($context, $objectManager, $storeManager);
    }

    /**
     * @return bool
     */
    public function isCustomerLogedIn()
    {
        return $this->_httpContext->getValue(CustomerContext::CONTEXT_AUTH);
    }

    /**
     * @param $customerId
     *
     * @return \Magento\Customer\Api\Data\CustomerInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getCustomerById($customerId)
    {
        return $this->customerRepositoryInterface->getById($customerId);
    }

    /**
     * @param $CusEmail
     *
     * @return \Magento\Customer\Api\Data\CustomerInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getCustomerByEmail($CusEmail)
    {
        return $this->customerRepositoryInterface->get($CusEmail);
    }

    /**
     * @param $customerId
     *
     * @return mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getIsApproved($customerId)
    {
        $value            = null;
        $customer         = $this->getCustomerById($customerId);
        $isApprovedObject = $customer->getCustomAttribute('is_approved');
        if (!$isApprovedObject) {
            return $value;
        }
        /** @var \Magento\Framework\View\Page\Config\Structure $isApprovedObject */
        $isApprovedObjectArray = $isApprovedObject->__toArray();
        $attributeCode         = $isApprovedObjectArray['attribute_code'];
        if ($attributeCode == 'is_approved') {
            $value = $isApprovedObjectArray['value'];
        }

        return $value;
    }

    /**
     * @param $isApprovedObject
     *
     * @return null
     */
    public function getValueOfAttrApproved($isApprovedObject)
    {
        if (!$isApprovedObject) {
            return null;
        }
        $value = null;
        /** @var \Magento\Framework\View\Page\Config\Structure $isApprovedObject */
        $isApprovedObject = $isApprovedObject->__toArray();
        $attributeCode    = $isApprovedObject['attribute_code'];
        if ($attributeCode == 'is_approved') {
            $value = $isApprovedObject['value'];
        }

        return $value;
    }

    /**
     * @param $customerId
     * @param $typeAction
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function approvalCustomerById($customerId, $typeAction)
    {
        $typeApproval = AttributeOptions::APPROVED;
        $customer     = $this->customerFactory->create()->load($customerId);
        $this->approvalAction($customer, $typeApproval);
        // send email
        if (!$this->getAutoApproveConfig() || $typeAction == TypeAction::COMMAND || $typeAction == TypeAction::API) {
            $this->emailApprovalAction($customer, $this->getEmailSetting('approve'));
        }
    }

    /**
     * @param $customerId
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function notApprovalCustomerById($customerId)
    {
        $typeApproval = AttributeOptions::NOTAPPROVE;
        $customer     = $this->customerFactory->create()->load($customerId);
        $this->approvalAction($customer, $typeApproval);
        // send email
        $this->emailApprovalAction($customer, $this->getEmailSetting('not-approve'));
    }

    /**
     * @param $customer
     * @param $typeApproval
     *
     * @throws \Exception
     */
    public function approvalAction($customer, $typeApproval)
    {
        /** @var \Magento\Customer\Model\Customer $customer */
        $customerData = $customer->getDataModel();
        if ($this->getValueOfAttrApproved($customerData->getCustomAttribute('is_approved')) != $typeApproval) {
            $customerData->setId($customer->getId());
            $customerData->setCustomAttribute('is_approved', $typeApproval);
            $customer->updateData($customerData);
            $customer->save();
        }
    }

    /**
     * @param $customerId
     * @param $actionRegister
     *
     * @throws \Exception
     */
    public function setApprovePendingById($customerId, $actionRegister)
    {
        $customer           = null;
        $customer           = $this->customer->load($customerId);
        $customerData       = $customer->getDataModel();
        $isApproveAttrValue = $this->getValueOfAttrApproved($customerData->getCustomAttribute('is_approved'));
        if ($isApproveAttrValue != AttributeOptions::PENDING) {
            $customerData->setId($customerId);
            $customerData->setCustomAttribute('is_approved', AttributeOptions::PENDING);
            $customer->updateData($customerData);
            $customer->save();
        }
        if ($isApproveAttrValue == AttributeOptions::PENDING && $actionRegister) {
            $this->emailApprovalAction($customer, $this->getEmailSetting('success'));
        }
    }

    /**
     * @return int
     */
    public function getStoreId()
    {
        return $this->storeManager->getStore()->getId();
    }

    /**
     * @return bool
     */
    public function isCustomerApprovalEnabled()
    {
        return $this->isEnabled();
    }

    /**
     * @return mixed|null
     */
    public function getCustomerGroupId()
    {
        return $this->_httpContext->getValue(CustomerContext::CONTEXT_GROUP);
    }

    /**
     * @return string
     */
    public function getRouteName()
    {
        return $this->_requestHttp->getRouteName();
    }

    /**
     * @return string
     */
    public function getFullAction()
    {
        return $this->_requestHttp->getFullActionName();
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getEnabledNoticeAdmin($storeId = null)
    {
        return $this->getModuleConfig('admin_notification_email/enabled', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getNoticeAdminTemplate($storeId = null)
    {
        return $this->getModuleConfig('admin_notification_email/template', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getSenderAdmin($storeId = null)
    {
        return $this->getModuleConfig('admin_notification_email/sender', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getRecipientsAdmin($storeId = null)
    {
        return preg_replace('/\s+/', '', $this->getModuleConfig('admin_notification_email/sendto', $storeId));
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getSenderCustomer($storeId = null)
    {
        return $this->getModuleConfig('customer_notification_email/sender', $storeId);
    }

    /**
     * @param string $type
     * @param null $storeId
     *
     * @return array
     */

    public function getEmailSetting($type, $storeId = null)
    {
        $emailMap = [
            'approve'     => 'customer_approve_email',
            'not_approve' => 'customer_not_approve_email',
            'success'     => 'customer_success_email',
        ];

        $isEnableEmailNotification = $this->getModuleConfig(
            'customer_notification_email/' . $emailMap[$type] . '/enabled',
            $storeId
        );
        $emailTemplate             = $this->getModuleConfig(
            'customer_notification_email/' . $emailMap[$type] . '/template',
            $storeId
        );

        return [
            'isEnable'      => $isEnableEmailNotification,
            'emailTemplate' => $emailTemplate
        ];
    }

    /**
     * @param $customer
     * @param $emailSettings
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function emailApprovalAction($customer, $emailSettings)
    {
        $storeId = $this->getStoreId();
        /** @var \Magento\Customer\Model\Customer $customer */
        $sendTo = $customer->getEmail();
        $sender = $this->getSenderCustomer();
        if ($this->getAutoApproveConfig()) {
            $sender = $this->getConfigValue('customer/create_account/email_identity');
        }
        $enableSendEmail   = $emailSettings['isEnable'];
        $typeTemplateEmail = $emailSettings['emailTemplate'];
        if ($enableSendEmail) {
            try {
                $this->sendMail(
                    $sendTo,
                    $customer,
                    $typeTemplateEmail,
                    $storeId,
                    $sender
                );
            } catch (\Exception $e) {
                $this->messageManager->addExceptionMessage($e, __($e->getMessage()));
            }
        }
    }

    /**
     * @param $customer
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function emailNotifyAdmin($customer)
    {
        $storeId = $this->getStoreId();
        $sender  = $this->getSenderAdmin();
        if ($this->getAutoApproveConfig()) {
            $sender = $this->getConfigValue('customer/create_account/email_identity');
        }
        $sendTo      = $this->getRecipientsAdmin();
        $sendToArray = explode(',', $sendTo);

        if ($this->getEnabledNoticeAdmin()) {
            // send email notify to admin
            foreach ($sendToArray as $recipient) {
                $this->sendMail(
                    $recipient,
                    $customer,
                    $this->getNoticeAdminTemplate(),
                    $storeId,
                    $sender
                );
            }
        }
    }

    /**
     * @param $sendTo
     * @param $customer
     * @param $emailTemplate
     * @param $storeId
     * @param $sender
     *
     * @return bool
     */
    public function sendMail($sendTo, $customer, $emailTemplate, $storeId, $sender)
    {
        /** @var \Magento\Customer\Model\Data\Customer $customer */
        try {
            $this->transportBuilder
                ->setTemplateIdentifier($emailTemplate)
                ->setTemplateOptions(
                    [
                        'area'  => Area::AREA_FRONTEND,
                        'store' => $storeId,
                    ]
                )
                ->setTemplateVars(
                    [
                        'firstname' => $customer->getFirstname(),
                        'lastname'  => $customer->getLastname(),
                        'email'     => $customer->getEmail(),
                        'loginurl'  => $this->_getUrl('customer/account/login'),
                    ]
                )
                ->setFrom($sender)
                ->addTo($sendTo);
            $transport = $this->transportBuilder->getTransport();
            $transport->sendMessage();

            return true;
        } catch (\Magento\Framework\Exception\MailException $e) {
            $this->_logger->critical($e->getLogMessage());
        }

        return false;
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getAutoApproveConfig($storeId = null)
    {
        return $this->getConfigGeneral('auto_approve', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getMessageAfterRegister($storeId = null)
    {
        return $this->getConfigGeneral('message_after_register', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getTypeNotApprove($storeId = null)
    {
        return $this->getConfigGeneral('type_not_approve', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getErrorMessage($storeId = null)
    {
        return $this->getConfigGeneral('error_message', $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getCmsRedirectPage($storeId = null)
    {
        return $this->getConfigGeneral('redirect_cms_page', $storeId);
    }

    /**
     * @param $path
     * @param $param
     *
     * @return string
     */
    public function getUrl($path, $param)
    {
        return $this->_getUrl($path, $param);
    }

    /**
     * @param $stringCode
     *
     * @return mixed
     */
    public function getRequestParam($stringCode)
    {
        return $this->_request->getParam($stringCode);
    }

    /**
     * @param $customerId
     *
     * @throws \Exception
     */
    public function autoApprovedOldCustomerById($customerId)
    {
        $customer     = $this->customerFactory->create()->load($customerId);
        $typeApproval = AttributeOptions::APPROVED;
        $this->approvalAction($customer, $typeApproval);
    }

    /**
     * Retrieve cookie manager
     *
     * @return     PhpCookieManager
     * @deprecated 100.1.0
     */
    public function getCookieManager()
    {
        if (!$this->cookieMetadataManager) {
            $this->cookieMetadataManager = ObjectManager::getInstance()->get(PhpCookieManager::class);
        }

        return $this->cookieMetadataManager;
    }

    /**
     * Retrieve cookie metadata factory
     *
     * @return     CookieMetadataFactory
     * @deprecated 100.1.0
     */
    public function getCookieMetadataFactory()
    {
        if (!$this->cookieMetadataFactory) {
            $this->cookieMetadataFactory = ObjectManager::getInstance()->get(CookieMetadataFactory::class);
        }

        return $this->cookieMetadataFactory;
    }
}
