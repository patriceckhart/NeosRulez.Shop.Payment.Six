<?php
namespace NeosRulez\Shop\Payment\Six\Payment;

use GuzzleHttp\Client;
use Neos\Flow\Annotations as Flow;
use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Utility\Algorithms;
use NeosRulez\Shop\Domain\Model\Order;
use NeosRulez\Shop\Payment\Payment\AbstractPayment;

/**
 * @Flow\Scope("singleton")
 */
class Six extends AbstractPayment
{

    /**
     * @param array $payment
     * @param array $args
     * @param string $successUri
     * @return string
     */
    public function execute(array $payment, array $args, string $successUri): string
    {
        $order = $this->orderRepository->findByOrderNumber($args['order_number']);
        $order->setCanceled(false);
        $order->setDone(true);
        $this->orderRepository->update($order);
        return $this->createPayment($payment, $order, $successUri, $args['failure_uri']);
    }

    /**
     * @param array $payment
     * @param Order $order
     * @param string $successUri
     * @param string $failureUri
     * @return string
     */
    private function createPayment(array $payment, Order $order, string $successUri, string $failureUri): string
    {
        $uniqueId = Algorithms::generateRandomToken(24);
        $username = $payment['username'];
        $password = $payment['password'];
        $url = $payment['url'];
        $customerId = $payment['customerId'];
        $terminalId = $payment['terminalId'];
        $orderSummary = json_decode($order->getSummary(), true);
        $payload = $this->createPayload($uniqueId, $customerId, $terminalId, $order->getOrdernumber(), $orderSummary['total'], $successUri, $failureUri);

        $client = new Client();
        $response = $client->request('POST', $url . 'Payment/v1/PaymentPage/Initialize', [
            'headers' => [
                'Content-type' => 'application/json',
                'Accept' => 'application/json; charset=utf-8',
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
            ],
            'body' => json_encode($payload),
        ]);
        $contents = $response->getBody()->getContents();
        $result = json_decode($contents, true);
        return $result['RedirectUrl'];
    }

    /**
     * @param string $uniqueId
     * @param string $customerId
     * @param string $terminalId
     * @param int $orderNumber
     * @param float $amount
     * @param string $successUri
     * @param string $failureUri
     * @return array
     */
    private function createPayload(string $uniqueId, string $customerId, string $terminalId, int $orderNumber, float $amount, string $successUri, string $failureUri): array
    {
        $payload['RequestHeader']['SpecVersion'] = '1.16';
        $payload['RequestHeader']['CustomerId'] = $customerId;
        $payload['RequestHeader']['RequestId'] =  $orderNumber . '-' . $uniqueId;
        $payload['RequestHeader']['RetryIndicator'] = 0;

        $payload['TerminalId'] = $terminalId;

        $payload['Payment']['Amount']['Value'] = (int) ($amount * 100);
        $payload['Payment']['Amount']['CurrencyCode'] = 'EUR';
        $payload['Payment']['OrderId'] = $orderNumber;
        $payload['Payment']['Description'] = 'Order';

        $payload['ReturnUrls']['Success'] = $this->generateSuccessUri($orderNumber, $successUri);
        $payload['ReturnUrls']['Fail'] = $failureUri;
        $payload['ReturnUrls']['Abort'] = $failureUri;

        $payload['Notification']['NotifyUrl'] = $failureUri;
        return $payload;
    }

}
