<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 */

/**
 * @category   Magenerds
 * @package    Magenerds_Ldap
 * @copyright  Copyright (c) 2017 TechDivision GmbH (http://www.techdivision.com)
 * @link       http://www.techdivision.com/
 * @link       https://github.com/Magenerds/Ldap
 * @author     Julian Schlarb <j.schlarb@techdivision.com>
 */
namespace Magenerds\Ldap\Plugin\Backend\Model\Auth\Credential;

use Closure;
use Magenerds\Ldap\Api\LdapClientInterface;
use Magenerds\Ldap\Model\Ldap\PasswordValidator;
use Magenerds\Ldap\Model\Ldap\UserMapper;
use Magento\Backend\Model\Auth\Credential\StorageInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\User\Model\User;
use Psr\Log\LoggerInterface;

/**
 * Class StoragePlugin
 * @package Magenerds\Ldap\Plugin\Backend\Model\Auth\Credential
 */
final class StoragePlugin
{
    /**
     * @var LdapClientInterface
     */
    private $ldapClient;

    /**
     * @var ManagerInterface
     */
    private $eventManager;

    /**
     * @var PasswordValidator
     */
    private $passwordValidator;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var UserMapper
     */
    private $userMapper;

    /**
     * @var \Magento\User\Model\ResourceModel\User
     */
    private $userResource;

    /**
     * StoragePlugin constructor.
     *
     * @param LoggerInterface $logger
     * @param LdapClientInterface $ldapClient
     * @param UserMapper $userMapper
     * @param ManagerInterface $eventManager
     * @param PasswordValidator $passwordValidator
     * @param \Magento\User\Model\ResourceModel\User $userResource
     * @internal param User $user
     */
    public function __construct(
        LoggerInterface $logger,
        LdapClientInterface $ldapClient,
        UserMapper $userMapper,
        ManagerInterface $eventManager,
        PasswordValidator $passwordValidator,
        \Magento\User\Model\ResourceModel\User $userResource
    )
    {
        $this->ldapClient = $ldapClient;
        $this->eventManager = $eventManager;
        $this->passwordValidator = $passwordValidator;
        $this->logger = $logger;
        $this->userMapper = $userMapper;
        $this->userResource = $userResource;
    }

    /**
     * @param StorageInterface $subject
     * @param Closure $proceed
     * @param $username
     * @param $password
     * @return bool
     * @throws LocalizedException
     */
    public function aroundAuthenticate(StorageInterface $subject, Closure $proceed, $username, $password)
    {
        // Skip ldap auth mechanism if someone replaced user
        if (!$subject instanceof User) {
            $msg = 'Ldap auth is unable to proceed. Type mismatch, expected [%s] but was [%s]';

            $this->logger->critical(sprintf($msg, User::class, get_class($subject)));

            return $proceed($username, $password);
        }

        $subject->loadByUsername($username);
        $result = false;

        $params = ['username' => $username, 'user' => $subject];
        $this->eventManager->dispatch('admin_user_authenticate_before', $params);

        try {
          $ldapAttributes = $this->ldapClient->getUserByUsername($username)->current();

          //Check if the result from the LDAP is valid
          if (!empty($ldapAttributes)){
            $this->userMapper->mapUser($ldapAttributes, $password, $subject);

            if ($this->ldapClient->checkLoginAgainstLDAP($username, $password)) {
                $this->userResource->save($subject);
                $result = true;
                $this->validateIdentity($subject);
            }
          } else { //Unvalid result, hand over to Magento
            $result = $proceed($username, $password);
          }
        } catch(Zend_Ldap_Exception $e){ //Okay, we did not find the user in LDAP, hand over to Magento
          $result = $proceed($username, $password);
        }

        $params = ['username' => $username, 'password' => $password, 'user' => $subject, 'result' => $result];
        $this->eventManager->dispatch('admin_user_authenticate_after', $params);

        if ($result === false) {
            $subject->unsetData();
        }
        return $result;
    }

    /**
     * Check if user is active and has any assigned role
     *
     * @param User $user
     * @throws AuthenticationException
     * @return void
     */
    private function validateIdentity(User $user)
    {
        $isActive = $user->getIsActive();

        if (empty($isActive)) {
            throw new AuthenticationException(
                __('You did not sign in correctly or your account is temporarily disabled.')
            );
        }

        if (!$user->hasAssigned2Role($user)) {
            throw new AuthenticationException(__('You need more permissions to access this.'));
        }
    }
}
