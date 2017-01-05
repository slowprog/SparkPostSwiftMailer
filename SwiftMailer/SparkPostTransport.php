<?php

namespace SlowProg\SparkPostSwiftMailer\SwiftMailer;

use SparkPost\SparkPost;
use GuzzleHttp\Client;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;

use \Swift_Events_EventDispatcher;
use \Swift_Events_EventListener;
use \Swift_Events_SendEvent;
use \Swift_Mime_Message;
use \Swift_Transport;
use \Swift_Attachment;
use \Swift_MimePart;

class SparkPostTransport implements Swift_Transport
{

    /**
     * @type Swift_Events_EventDispatcher
     */
    protected $dispatcher;

    /** @var string|null */
    protected $apiKey;

    /** @var array|null */
    protected $resultApi;

    /** @var array|null */
    protected $fromEmail;

    /**
     * @param Swift_Events_EventDispatcher $dispatcher
     */
    public function __construct(Swift_Events_EventDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
        $this->apiKey = null;
    }

    /**
     * Not used
     */
    public function isStarted()
    {
        return false;
    }

    /**
     * Not used
     */
    public function start()
    {
    }

    /**
     * Not used
     */
    public function stop()
    {
    }

    /**
     * @param string $apiKey
     * @return $this
     */
    public function setApiKey($apiKey)
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getApiKey()
    {
        return $this->apiKey;
    }

    /**
     * @return \SparkPost\SparkPost
     * @throws \Swift_TransportException
     */
    protected function createSparkPost()
    {
        if ($this->apiKey === null)
            throw new \Swift_TransportException('Cannot create instance of \SparkPost\SparkPost while API key is NULL');

        return new SparkPost(
            new GuzzleAdapter(new Client()),
            ['key' => $this->apiKey]
        );
    }

    /**
     * @param Swift_Mime_Message $message
     * @param null $failedRecipients
     * @return int Number of messages sent
     */
    public function send(Swift_Mime_Message $message, &$failedRecipients = null)
    {
        $this->resultApi = null;
        if ($event = $this->dispatcher->createSendEvent($this, $message)) {
            $this->dispatcher->dispatchEvent($event, 'beforeSendPerformed');
            if ($event->bubbleCancelled()) {
                return 0;
            }
        }

        $sendCount = 0;

        $sparkPostMessage = $this->getSparkPostMessage($message);

        $sparkPost = $this->createSparkPost();

        $promise= $sparkPost->transmissions->post($sparkPostMessage);

        try {
            $response = $promise->wait();
            $this->resultApi = $response->getBody();
        } catch (\Exception $e) {
            throw $e;
        }

        $sendCount = $this->resultApi['results']['total_accepted_recipients'];

        if ($this->resultApi['results']['total_rejected_recipients'] > 0) {
            $failedRecipients[] = $this->fromEmail;
        }

        if ($event) {
            if ($sendCount > 0) {
                $event->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
            } else {
                $event->setResult(Swift_Events_SendEvent::RESULT_FAILED);
            }

            $this->dispatcher->dispatchEvent($event, 'sendPerformed');
        }

        return $sendCount;
    }

    /**
     * @param Swift_Events_EventListener $plugin
     */
    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        $this->dispatcher->bindEventListener($plugin);
    }

    /**
     * @return array
     */
    protected function getSupportedContentTypes()
    {
        return array(
            'text/plain',
            'text/html'
        );
    }

    /**
     * @param string $contentType
     * @return bool
     */
    protected function supportsContentType($contentType)
    {
        return in_array($contentType, $this->getSupportedContentTypes());
    }

    /**
     * @param Swift_Mime_Message $message
     * @return string
     */
    protected function getMessagePrimaryContentType(Swift_Mime_Message $message)
    {
        $contentType = $message->getContentType();

        if($this->supportsContentType($contentType)){
            return $contentType;
        }

        // SwiftMailer hides the content type set in the constructor of Swift_Mime_Message as soon
        // as you add another part to the message. We need to access the protected property
        // _userContentType to get the original type.
        $messageRef = new \ReflectionClass($message);
        if($messageRef->hasProperty('_userContentType')){
            $propRef = $messageRef->getProperty('_userContentType');
            $propRef->setAccessible(true);
            $contentType = $propRef->getValue($message);
        }

        return $contentType;
    }

    /**
     * https://jsapi.apiary.io/apis/sparkpostapi/introduction/subaccounts-coming-to-an-api-near-you-in-april!.html
     *
     * @param Swift_Mime_Message $message
     * @return array SparkPost Send Message
     * @throws \Swift_SwiftException
     */
    public function getSparkPostMessage(Swift_Mime_Message $message)
    {
        $contentType = $this->getMessagePrimaryContentType($message);

        $fromAddresses = $message->getFrom();
        $fromEmails = array_keys($fromAddresses);
        list($fromFirstEmail, $fromFirstName) = each($fromAddresses);
        $this->fromEmail = $fromFirstEmail;

        $toAddresses = $message->getTo();
        $ccAddresses = $message->getCc() ? $message->getCc() : [];
        $bccAddresses = $message->getBcc() ? $message->getBcc() : [];
        $replyToAddresses = $message->getReplyTo() ? $message->getReplyTo() : [];

        $recipients = array();
        $cc = array();
        $bcc = array();
        $attachments = array();
        $headers = array();
        $tags = array();
        $inlineCss = null;

        foreach ($toAddresses as $toEmail => $toName) {
            $recipients[] = array(
                'address' => array(
                    'email' => $toEmail,
                    'name'  => $toName,
                )
            );
        }
        $reply_to = null;
        foreach ($replyToAddresses as $replyToEmail => $replyToName) {
            if ($replyToName){
                $reply_to= sprintf('%s <%s>', $replyToName, $replyToEmail);
            } else {
                $reply_to = $replyToEmail;
            }
        }

        foreach ($ccAddresses as $ccEmail => $ccName) {
            $cc[] = array(
                'email' => $ccEmail,
                'name'  => $ccName,
            );
        }

        foreach ($bccAddresses as $bccEmail => $bccName) {
            $bcc[] = array(
                'email' => $bccEmail,
                'name'  => $bccName,
            );
        }

        $bodyHtml = $bodyText = null;

        if($contentType === 'text/plain'){
            $bodyText = $message->getBody();
        }
        elseif($contentType === 'text/html'){
            $bodyHtml = $message->getBody();
        }
        else{
            $bodyHtml = $message->getBody();
        }

        foreach ($message->getChildren() as $child) {

            if ($child instanceof Swift_Attachment) {
                $attachments[] = array(
                    'type'    => $child->getContentType(),
                    'name'    => $child->getFilename(),
                    'data' => base64_encode($child->getBody())
                );
            } elseif ($child instanceof Swift_MimePart && $this->supportsContentType($child->getContentType())) {
                if ($child->getContentType() == "text/html") {
                    $bodyHtml = $child->getBody();
                } elseif ($child->getContentType() == "text/plain") {
                    $bodyText = $child->getBody();
                }
            }
        }

        if ($message->getHeaders()->has('List-Unsubscribe')) {
            $headers['List-Unsubscribe'] = $message->getHeaders()->get('List-Unsubscribe')->getValue();
        }

        if ($message->getHeaders()->has('X-MC-InlineCSS')) {
            $inlineCss = $message->getHeaders()->get('X-MC-InlineCSS')->getValue();
        }

        if($message->getHeaders()->has('X-MC-Tags')){
            /** @var \Swift_Mime_Headers_UnstructuredHeader $tagsHeader */
            $tagsHeader = $message->getHeaders()->get('X-MC-Tags');
            $tags = explode(',', $tagsHeader->getValue());
        }

        $sparkPostMessage = array(
            'recipients' => $recipients,
            'reply_to'   => $reply_to,
            'inline_css' => $inlineCss,
            'tags'       => $tags,
            'content'    => array (
                'from' => array (
                    'name'  => $fromFirstName,
                    'email' => $fromFirstEmail,
                ),
                'subject' => $message->getSubject(),
                'html'    => $bodyHtml,
                'text'    => $bodyText,
            ),
        );

        if(!empty($cc))
            $sparkPostMessage['cc'] = $cc;
        if(!empty($bcc))
            $sparkPostMessage['bcc'] = $bcc;
        if(!empty($headers))
            $sparkPostMessage['headers'] = $headers;

        if (count($attachments) > 0) {
            $sparkPostMessage['attachments'] = $attachments;
        }

        return $sparkPostMessage;
    }

    /**
     * @return null|array
     */
    public function getResultApi()
    {
        return $this->resultApi;
    }

}
