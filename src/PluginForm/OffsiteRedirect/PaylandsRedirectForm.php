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
    $Paylands_endpoint = $payment_gateway_plugin->getPaymentAPIEndpoint();

    // Format amount
    $amount = $order->getTotalPrice()->getCurrencyCode() == 'IDR'
      ? floor($order->getTotalPrice()->getNumber())  // Indonesian Rupiah should be sent without digits behind comma
      : sprintf('%0.2f', $order->getTotalPrice()->getNumber());

    // Prepare the first phase request array
    $request = array(
      // TODO
    );

    // Call a service
    $http = \Drupal::httpClient()
      ->post($Paylands_endpoint, [
        'body' => json_encode($request),
        'http_errors' => FALSE,
        'headers' => [
          'Content-Type' => 'application/json',
        ],
      ]);
    $body = $http->getBody()->getContents();
    $response = json_decode($body, TRUE);

    // Validate response code
    if (!isset($response['response_code'])) {
      throw new InvalidResponseException('No response code.');
    }
    if ($response['response_code'] != 0) {
      throw new InvalidResponseException('Invalid request: ' . $response['response_msg']);
    }

    // Validate payment URL
    if (empty($response['payment_url'])) {
      throw new InvalidResponseException('Invalid response, no payment_url');
    }

    // Associated payment with the transaction.
    $payment->setRemoteId($response['transaction_id']);
    $payment->save();

    return $this->buildRedirectForm($form, $form_state, $response['payment_url'], array());
  }
}
