<?php

namespace Drupal\commerce_paylands\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "paylands_redirect",
 *   label = "Paylands Redirect",
 *   display_label = "Paylands Redirect",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_paylands\PluginForm\OffsiteRedirect\PaylandsRedirectForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa",
 *   },
 * )
 */
class PaylandsRedirect extends OffsitePaymentGatewayBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'api_key' => '',
      'signature' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['api_key'] = [
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => $this->t('Merchant ID'),
      '#description' => $this->t('Paylands Merchant ID given to you when registering for Paylands account.'),
      '#default_value' => $this->configuration['api_key'],
    ];

    $form['signature'] = [
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => $this->t('Signature'),
      '#description' => $this->t('Paylands signature given to you when registering for Paylands account.'),
      '#default_value' => $this->configuration['signature'],
    ];

    $form['service'] = [
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => $this->t('Service'),
      '#description' => $this->t('Paylands service used to make tje payment.'),
      '#default_value' => $this->configuration['service'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['api_key'] = $values['api_key'];
      $this->configuration['signature'] = $values['signature'];
    }
  }

  /**
   * Return correct payment API endpoint
   *
   * @return string
   */
  public function getPaymentAPIEndpoint() {
    $config = $this->getConfiguration();
    $paylands_endpoint = 'https://api.paylands.com/v1';
    if ($config['mode'] == 'test') {
      $paylands_endpoint = ' https://api.paylands.com/v1/sandbox';
    }
    return $paylands_endpoint;
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    parent::onCancel($order, $request);
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    parent::onNotify($request);

    // Get response
    $transaction_response = json_decode($request->getContent(), true);
    if (empty($transaction_response)) {
      throw new InvalidResponseException('No response returned.');
    }

    $this->handleTransaction($transaction_response);
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    parent::onReturn($order, $request);

    // Get response
    $transaction_response = json_decode($request->getContent(), true);
    if (empty($transaction_response)) {
      throw new InvalidResponseException('No response returned.');
    }

    $this->handleTransaction($transaction_response);
  }

  /**
   * Helper function to hanlde the transaction for both onNotify and onReturn.
   *
   * @param $config
   * @param $order
   * @param $response
   */
  private function handleTransaction($response) {
    // Validate order_id
    if (empty($response['order_id'])) {
      throw new InvalidResponseException('Order ID not provided in response.');
    }
    $order = \Drupal::entityTypeManager()->getStorage('commerce_order')->load($response['order_id']);
    if (empty($order)) {
      throw new PaymentGatewayException('Order ID returned from the service not found.');
    }

    // Check if we have a payment matching the transaction ID
    $payments = \Drupal::entityTypeManager()->getStorage('commerce_payment')->loadMultipleByOrder($order);
    $payment = NULL;
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    foreach($payments as $order_payment) {
      if ($order_payment->getRemoteId() == $response['transaction_id']) {
        $payment = $order_payment;
        break;
      }
    }
    if (empty($payment)) {
      throw new InvalidResponseException('No payments matching returned transaction ID.');
    }

    // Validate response code
    if (!isset($response['response_code'])) {
      throw new InvalidResponseException('No response code.');
    }
    if ($response['response_code'] != 0) {
      throw new DeclineException($this->t('Payment has been declined by the gateway (@error_code).', [
        '@error_code' => $response['response_code'],
      ]), $response['response_code']);
    }

    // Set payment as completed
    $payment->setAuthorizedTime(REQUEST_TIME);
    $payment->setCompletedTime(REQUEST_TIME);
    $payment->setState('completed');
    $payment->save();

    // TODO: Handle authorisations as well, currently only Sale allowed.
  }
}
