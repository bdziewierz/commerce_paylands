<?php

namespace Drupal\commerce_paylands\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\commerce_paylands\Plugin\Commerce\PaymentGateway\PaylandsRedirect;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class PaylandsRedirectForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;

    /** @var \Drupal\commerce_paylands\Plugin\Commerce\PaymentGateway\PaylandsRedirect $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $config = $payment_gateway_plugin->getConfiguration();
    $order = $payment->getOrder();
    $current_language = \Drupal::languageManager()->getCurrentLanguage();

    /** @var \Drupal\address\AddressInterface $billing_address */
    $billing_address = $order->getBillingProfile()->get('address')->first();

    // Validate api_key
    if (empty($config['api_key'])) {
      throw new PaymentGatewayException('Merchant ID not provided.');
    }

    // Validate signature
    if (empty($config['signature'])) {
      throw new PaymentGatewayException('Validation code not provided.');
    }

    // Determine correct endpoint
    $paylands_endpoint = $payment_gateway_plugin->getPaymentAPIEndpoint();

    // Format amount (in cents)
    $amount = doubleval($order->getTotalPrice()->getNumber()) * 100;

    // Prepare the first phase request array
    $request = array (
      'operative' => 'AUTHORIZATION',
      'service' => $config['service'],
      'signature' => $config['signature'],
      'amount' => $amount,
      'customer_ext_id' => $order->id(),
      'additional' => $order->getEmail(),
      'description' => $billing_address->getGivenName() . ' ' . $billing_address->getFamilyName() . ' (' . $order->id() . ')',
      'secure' => true,
      'url_post' => $payment_gateway_plugin->getNotifyUrl()->toString(),
      'url_ok' => $form['#return_url'],
      'url_ko' => $form['#return_url'],
      'template_uuid' => '6a93c26e-954d-47ea-83bf-21fa29b68f28',
    );

    // Call a staging service
    $http = \Drupal::httpClient()
      ->post($paylands_endpoint . '/payment', [
        'auth' => [$config['api_key']],
        'body' => json_encode($request),
        'http_errors' => FALSE,
        'headers' => [
          'Content-Type' => 'application/json',
        ],
      ]);
    $body = $http->getBody()->getContents();
    $response = json_decode($body, TRUE);

    // Validate response code
    if (!isset($response['code'])) {
      throw new InvalidResponseException('No response code.');
    }
    if ($response['code'] != 200) {
      throw new InvalidResponseException('Invalid request: ' . $response['message']);
    }

    // Validate payment URL
    if (empty($response['order']['token'])) {
      throw new InvalidResponseException('Invalid response, no token');
    }

    // Associated payment with the order uuid.
    $payment->setRemoteId($response['order']['uuid']);
    $payment->save();

    $redirect_url = $paylands_endpoint . '/payment/process/' . $response['order']['token'];
    return $this->buildRedirectForm($form, $form_state, $redirect_url, array('lang' => $current_language->getId()));
  }
}
