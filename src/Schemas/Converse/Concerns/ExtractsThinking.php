<?php

namespace Prism\Bedrock\Schemas\Converse\Concerns;

trait ExtractsThinking
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractThinking(array $data): array
    {
        $content = data_get($data, 'output.message.content', []);

        $thinking = '';

        foreach ($content as $item) {
            if ($text = data_get($item, 'reasoningContent.reasoningText.text')) {
                $thinking .= $text;
            }
        }

        if ($thinking === '') {
            return [];
        }

        return ['thinking' => $thinking];
    }
}
