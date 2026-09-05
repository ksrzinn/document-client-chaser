<?php

use App\Mail\DocumentRequestReminder;
use Illuminate\Contracts\Queue\ShouldQueue;

it('is queueable', function () {
    expect(new DocumentRequestReminder(
        businessName: 'Acme',
        clientName: 'John',
        requestMessage: null,
        dueAt: null,
        link: 'https://example.com',
        missingItemNames: ['Invoice'],
    ))->toBeInstanceOf(ShouldQueue::class);
});

it('has a subject that identifies it as a reminder and names the business', function () {
    $mail = new DocumentRequestReminder(
        businessName: 'Acme Bookkeeping',
        clientName: 'John',
        requestMessage: null,
        dueAt: null,
        link: 'https://example.com',
        missingItemNames: ['Invoice'],
    );

    expect($mail->envelope()->subject)->toContain('Reminder')->toContain('Acme Bookkeeping');
});

it('renders client name, missing items, link and due date', function () {
    $mail = new DocumentRequestReminder(
        businessName: 'Acme Bookkeeping',
        clientName: 'John Smith',
        requestMessage: 'Please hurry.',
        dueAt: '2026-10-15',
        link: 'https://example.com/request/abc',
        missingItemNames: ['Bank statement', 'Invoice'],
    );

    $rendered = $mail->render();

    expect($rendered)
        ->toContain('John Smith')
        ->toContain('Bank statement')
        ->toContain('Invoice')
        ->toContain('https://example.com/request/abc')
        ->toContain('2026-10-15')
        ->toContain('Please hurry.');
});

it('omits the due date section when there is none', function () {
    $mail = new DocumentRequestReminder(
        businessName: 'Acme',
        clientName: 'John',
        requestMessage: null,
        dueAt: null,
        link: 'https://example.com',
        missingItemNames: ['Invoice'],
    );

    expect($mail->render())->not->toContain('Please upload the requested documents by');
});

it('escapes malicious content in user-controlled fields', function () {
    $mail = new DocumentRequestReminder(
        businessName: '<script>alert(1)</script>',
        clientName: 'John',
        requestMessage: '<img src=x onerror=alert(1)>',
        dueAt: null,
        link: 'https://example.com',
        missingItemNames: ['<b>Invoice</b>'],
    );

    $rendered = $mail->render();

    expect($rendered)
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('<img src=x onerror=alert(1)>')
        ->not->toContain('<b>Invoice</b>');
});
