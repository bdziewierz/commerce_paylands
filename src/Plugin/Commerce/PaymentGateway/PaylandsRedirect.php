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
      'service' => '',
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
      '#title' => $this->t('API Key'),
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
      $this->configuration['service'] = $values['service'];
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
      $paylands_endpoint = 'https://api.paylands.com/v1/sandbox';
    }
    return $paylands_endpoint;
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    parent::onNotify($request);

    // Get notification response
    $response = json_decode($request->getContent(), true);
    if (empty($response)) {
      throw new InvalidResponseException('No response returned.');
    }

    $this->handleTransaction($response);
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    parent::onReturn($order, $request);

    $paylands_endpoint = $this->getPaymentAPIEndpoint();

    // Get Drupal's payment object
    $payments = \Drupal::entityTypeManager()->getStorage('commerce_payment')->loadMultipleByOrder($order);
    if (empty($payments)) {
      throw new InvalidResponseException('No payments for given order.');
    }

    // Validate last payment object. If it's completed (validated in Notify),
    // then we're good. Otherwise, check the status with API.

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = end($payments);
    if (!$payment->isCompleted()) {

      $order_uuid = $payment->getRemoteId();
      if (empty($order_uuid)) {
        throw new InvalidResponseException('No order_uuid for given payment.');
      }

      /** @var \Drupal\commerce_paylands\Plugin\Commerce\PaymentGateway\PaylandsRedirect $payment_gateway_plugin */
      $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
      $config = $payment_gateway_plugin->getConfiguration();
      $http = \Drupal::httpClient()
        ->get($paylands_endpoint . '/order/' . $order_uuid, [
          'auth' => [$config['api_key']],
          'http_errors' => FALSE,
          'headers' => [
            'Content-Type' => 'application/json',
          ],
        ]);
      $body = $http->getBody()->getContents();
      $response = json_decode($body, TRUE);
      if (empty($response)) {
        throw new InvalidResponseException('No response returned.');
      }

      $this->handleTransaction($response);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    parent::onCancel($order, $request);
  }

  /**
   * Helper function to handle the transaction for both onNotify and onReturn.
   *
   * @param $config
   * @param $order
   * @param $response
   */
  private function handleTransaction($response) {
    // Validate if customer id is returned. We store Drupal's order ID in this field.
    // This way it will work for both anonymous and authenticated purchases.
    if (empty($response['order']['customer'])) {
      throw new InvalidResponseException('Order ID not provided in response.');
    }
    
    $order = \Drupal::entityTypeManager()->getStorage('commerce_order')->load($response['order']['customer']);
    if (empty($order)) {
      throw new PaymentGatewayException('Order ID returned from the service not found in Drupal.');
    }

    // Get Drupal's payment object
    $payments = \Drupal::entityTypeManager()->getStorage('commerce_payment')->loadMultipleByOrder($order);
    if (empty($payments)) {
      throw new InvalidResponseException('No payments for given order.');
    }
    $payment = NULL;
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    foreach($payments as $order_payment) {
      if ($order_payment->getRemoteId() == $response['order']['uuid']) {
        $payment = $order_payment;
        break;
      }
    }
    if (empty($payment)) {
      throw new InvalidResponseException('No payments matching returned transaction ID.');
    }

    // Get Payland's transaction
    if (empty($response['order']['transactions'])) {
      throw new InvalidResponseException('No transactions information.');
    }
    $transaction = array_pop($response['order']['transactions']);

    if (!isset($transaction['status'])) {
      throw new InvalidResponseException('No transaction status information.');
    }
    if ($transaction['status'] != 'SUCCESS') {
      throw new DeclineException($this->t('Payment has been declined by the gateway (@error).', [
        '@error' => $transaction['error'],
      ]), $transaction['error']);
    }

    // Set payment as completed
    $payment->setAuthorizedTime(REQUEST_TIME);
    $payment->setCompletedTime(REQUEST_TIME);
    $payment->setState('completed');
    $payment->save();
  }
}
