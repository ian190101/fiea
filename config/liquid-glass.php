<?php
declare(strict_types=1);

return [
    'defaults' => [
        'cornerRadius' => 32, 'zRadius' => 45, 'bevelMode' => 0,
        'refraction' => 0.69, 'blurAmount' => 0.42, 'chromAberration' => 0.04,
        'edgeHighlight' => 0.08, 'specular' => 0.40, 'fresnel' => 1.0,
        'shadowOpacity' => 0.28, 'shadowSpread' => 16, 'shadowOffsetY' => 4,
        'opacity' => 1.0, 'saturation' => 0.0, 'tintStrength' => 0.0,
        'brightness' => 0.0, 'distortion' => 0.0, 'floating' => false, 'button' => false,
    ],
    'presets' => [
        'panel' => [],
        'sidebar' => ['blurAmount' => 0.92, 'refraction' => 0.58, 'edgeHighlight' => 0.08, 'fresnel' => 0.95],
        'button' => ['button' => true, 'bevelMode' => 1, 'cornerRadius' => 999, 'zRadius' => 28, 'refraction' => 0.78, 'specular' => 0.65, 'edgeHighlight' => 0.12],
    ],
    'ranges' => [
        'blurAmount' => [0, 1, 0.01], 'refraction' => [0, 2, 0.01],
        'chromAberration' => [0, 0.2, 0.001], 'edgeHighlight' => [0, 1, 0.01],
        'specular' => [0, 1, 0.01], 'fresnel' => [0, 2, 0.01],
        'distortion' => [0, 1, 0.01], 'cornerRadius' => [0, 999, 1],
        'zRadius' => [1, 100, 1], 'opacity' => [0.2, 1, 0.01],
        'saturation' => [-1, 1, 0.01], 'tintStrength' => [0, 1, 0.01],
        'brightness' => [-0.5, 0.5, 0.01], 'shadowOpacity' => [0, 1, 0.01],
        'shadowSpread' => [0, 100, 1], 'shadowOffsetY' => [-30, 60, 1],
    ],
];
