<?php

use App\Services\StreamProfileRuleEvaluator;

beforeEach(function () {
    $this->evaluator = new StreamProfileRuleEvaluator;
});

function probeStats(array $video = [], array $audio = []): array
{
    return [
        ['stream' => array_merge([
            'codec_type' => 'video',
            'codec_name' => 'h264',
            'width' => 1920,
            'height' => 1080,
            'avg_frame_rate' => '25/1',
        ], $video)],
        ['stream' => array_merge([
            'codec_type' => 'audio',
            'codec_name' => 'aac',
            'channels' => 2,
        ], $audio)],
    ];
}

it('exposes a synthetic video.resolution field built from width × height', function () {
    $context = $this->evaluator->buildProbeContext(probeStats());

    expect($context['video.resolution'])->toBe('1920x1080');
});

it('omits video.resolution when width or height is missing', function () {
    $context = $this->evaluator->buildProbeContext(probeStats(['height' => null]));

    expect($context['video.resolution'])->toBeNull();
});

it('returns false from matches() when the condition list is empty', function () {
    expect($this->evaluator->matches([], probeStats()))->toBeFalse();
});

it('matches when all conditions hold (AND)', function () {
    $conditions = [
        ['field' => 'video.codec_name', 'op' => '=', 'value' => 'h264'],
        ['field' => 'video.height', 'op' => '>=', 'value' => 1080],
    ];

    expect($this->evaluator->matches($conditions, probeStats(), 'all'))->toBeTrue();
});

it('does not match in AND mode when one condition fails', function () {
    $conditions = [
        ['field' => 'video.codec_name', 'op' => '=', 'value' => 'h264'],
        ['field' => 'video.height', 'op' => '>=', 'value' => 2160],
    ];

    expect($this->evaluator->matches($conditions, probeStats(), 'all'))->toBeFalse();
});

it('matches in OR mode when any single condition holds', function () {
    $conditions = [
        ['field' => 'video.resolution', 'op' => '=', 'value' => '1920x1080'],
        ['field' => 'video.resolution', 'op' => '=', 'value' => '1280x720'],
    ];

    expect($this->evaluator->matches($conditions, probeStats(), 'any'))->toBeTrue();
    expect($this->evaluator->matches($conditions, probeStats(['width' => 1280, 'height' => 720]), 'any'))->toBeTrue();
    expect($this->evaluator->matches($conditions, probeStats(['width' => 720, 'height' => 576]), 'any'))->toBeFalse();
});

it('returns false from matches() when probe data is null', function () {
    $conditions = [['field' => 'video.codec_name', 'op' => '=', 'value' => 'h264']];

    expect($this->evaluator->matches($conditions, null))->toBeFalse();
});
