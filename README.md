# SparkPostSwiftMailer
A SwiftMailer transport implementation for SparkPost

## Installation

Require the package with composer

    composer require slowprog/sparkpost-swiftmailer

## Usage

    $transport = new SparkPostTransport($dispatcher);
    $transport->setApiKey('asdfasdfasdf');
    $transport->send($message);
