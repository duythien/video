<?php
/**
 * Phanbook : Delightfully simple forum and Q&A software
 *
 * Licensed under The GNU License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @link    http://phanbook.com Phanbook Project
 * @since   1.0.0
 * @author  Phanbook <hello@phanbook.com>
 * @license http://www.gnu.org/licenses/old-licenses/gpl-2.0.txt
 */
namespace Phanbook\Oauth\Controllers;

use Phanbook\Models\Users;
use Phanbook\Oauth\Forms\SignupForm;
use Phanbook\Oauth\Forms\ForgotPasswordForm;
use Phanbook\Oauth\Forms\ResetPasswordForm;

/**
 * Class RegisterController
 *
 * @package Phanbook\Oauth\Controllers
 */
class RegisterController extends ControllerBase
{

    /**
     * @return \Phalcon\Http\ResponseInterface
     */
    public function resetpasswordAction()
    {
        if ($this->session->has('auth')) {
            $this->view->disable();

            return $this->response->redirect();
        }
        $passwordForgotHash = $this->request->getQuery('forgothash');
        if (empty($passwordForgotHash)) {
            $this->flashSession->error('Hack attempt!!!');

            return $this->response->redirect();
        }

        $object  = Users::findFirstByPasswdForgotHash($passwordForgotHash);

        if (!$object) {
            $this->flashSession->error(t('Invalid data.'));

            return $this->response->redirect();
        }

        $form             = new ResetPasswordForm;
        $this->view->form = $form;

        if ($this->request->isPost()) {
            if (!$form->isValid($_POST)) {
                foreach ($form->getMessages() as $message) {
                    $this->flashSession->error($message);
                }
            } else {
                $password = $this->request->getPost('password_new_confirm');
                $object->setPasswd($this->security->hash($password));
                $object->setPasswdForgotHash(null);
                if (!$object->save()) {
                    $this->displayModelErrors($object);
                } else {
                    $this->flashSession->success(t('Your password was changed successfully.'));
                    //Assign to session
                    $this->auth->check(
                        [
                            'email' => $object->getEmail(),
                            'password' => $password,
                            'remember' => true
                        ]
                    );
                    return $this->response->redirect();
                }
            }
        }
    }

    /**
     * It will render form after user signup
     *
     */
    public function indexAction()
    {
        $registerHash = $this->request->getQuery('registerhash');


        if (empty($registerHash)) {
            $this->flashSession->error('Hack attempt!!!');

            return $this->response->redirect('/');
        }

        if ($this->auth->getAuth()) {
            $this->view->disable();

            return $this->response->redirect();
        }

        $object         = Users::findFirstByRegisterHash($registerHash);

        if (!$object) {
            $this->flashSession->error('Invalid data.');

            return $this->response->redirect();
        }

        $form             = new ResetPasswordForm;
        $this->view->form = $form;

        if ($this->request->isPost()) {
            if (!$form->isValid($_POST)) {
                foreach ($form->getMessages() as $message) {
                    $this->flashSession->error($message);
                }
            } else {
                $password = $this->request->getPost('password_new_confirm');
                $object->setPasswd($this->security->hash($password));
                $object->setRegisterHash(null);
                $object->setStatus(Users::STATUS_ACTIVE);
                if (!$object->save()) {
                    $this->displayModelErrors($object);
                    return 0;
                } else {
                    $this->flashSession->success(t('Your password was changed successfully.'));

                    //Assign to session
                    $this->auth->check(
                        [
                            'email' => $object->getEmail(),
                            'password' => $password,
                            'remember' => true
                        ]
                    );
                    return $this->response->redirect();
                }
            }
        }
        $this->view->pick('register/resetpassword');
    }

    /**
     * @return \Phalcon\Http\ResponseInterface
     */
    public function signupAction()
    {
        if ($this->auth->getAuth()) {
            return $this->response->redirect();
        }

        $form = new SignupForm;

        if ($this->request->isPost()) {
            if (!$this->checkCaptcha()) {
                $this->flashSession->error(t('prove your humanity'));
                return $this->currentRedirect();
            }
            $object = new Users();
            $form->bind($_POST, $object);

            if (!$form->isValid($_POST)) {
                foreach ($form->getMessages() as $message) {
                    $this->flashSession->error($message);
                }
                return $this->currentRedirect();
            }

            $registerHash = md5(uniqid(rand(), true));
            $randomPasswd = substr(md5(microtime()), 0, 7);

            $object->setPasswd($this->security->hash($randomPasswd));
            $object->setRegisterhash($registerHash);
            $object->setStatus(Users::STATUS_PENDING);
            $object->setGender(Users::GENDER_UNKNOWN);
            $this->db->begin();
            if (!$object->save()) {
                $this->db->rollback();
                $this->displayModelErrors($object);
                return $this->currentRedirect();
            }

            $params = [
                'subject'  => t('Registration'),
                'link'     => ($this->request->isSecure()
                        ? 'https://' : 'http://') . $this->request->getHttpHost()
                    . '/oauth/register?registerhash=' . $registerHash
            ];
            if (!$this->mail->send($object->getEmail(), 'registration', $params)) {
                $this->db->rollback();
                $this->flashSession->error(t('Error sending registration email.'));
                return $this->currentRedirect();
            } else {
                $this->db->commit();
                $this->flashSession->success(
                    t(
                        'Your account was successfully created.
                        An email was sent to your address in order to continue the process.'
                    )
                );
            }

            return $this->response->redirect();
        }
        $siteKey = isset($this->config->reCaptcha->siteKey) ? $this->config->reCaptcha->siteKey : '';
        $this->view->setVar('siteKey', $siteKey);
        $this->view->form = $form;
    }

        /**
     * @return \Phalcon\Http\ResponseInterface
     */
    public function forgotpasswordAction()
    {
        //Resets any "template before" layouts because we use mutiple theme
        $this->view->cleanTemplateBefore();
        if ($this->session->has('auth')) {
            $this->view->disable();

            return $this->response->redirect();
        }

        $form = new ForgotPasswordForm;

        if ($this->request->isPost()) {
            if (!$form->isValid($_POST)) {
                foreach ($form->getMessages() as $message) {
                    $this->flashSession->error($message);
                }
            } else {
                $object = Users::findFirstByEmail($this->request->getPost('email'));
                if (!$object) {
                    // @TODO: Implement brute force protection
                    $this->flashSession->error(t('User not found.'));
                    return $this->currentRedirect();
                }
                $lastpass = $object->getLastPasswdReset();
                if (!empty($lastpass)
                    && (time() - $object->getLastPasswdReset())> $this->config->application->passwdResetInterval //password reset interval on configuration
                ) {
                    $this->flashSession->error(
                        t('You need to wait ') . (date('Y-m-d H:i:s') - $object->getLastPasswdReset()) . ' minutes'
                    );
                    return $this->currentRedirect();
                }

                $passwordForgotHash = sha1('forgot' . microtime());
                $object->setPasswdForgotHash($passwordForgotHash);
                $object->setLastPasswdReset(time());

                if (!$object->save()) {
                    $this->displayModelErrors($object);
                } else {
                    $params = [
                        'fullname'  => $object->getName(),
                        'link'      => ($this->request->isSecure()
                                            ? 'https://' : 'http://') . $this->request->getHttpHost()
                                        . '/oauth/resetpassword?forgothash=' . $passwordForgotHash
                    ];
                    if (!$this->mail->send($object->getEmail(), 'forgotpassword', $params)) {
                        $this->flashSession->error(t('Error sendig email.'));
                    } else {
                        $this->flashSession->success(
                            t('An email was sent to your address in order to continue with the reset password process.')
                        );

                        return $this->response->redirect();
                    }
                }
            }
        }
        $this->view->form = $form;
    }
}
