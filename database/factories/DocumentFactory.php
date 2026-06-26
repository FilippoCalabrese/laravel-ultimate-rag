<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sellinnate\RagEngine\Models\Document;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        $content = $this->faker->text(400);

        return [
            'tenant_id' => 'default',
            'source_type' => 'text',
            'content_hash' => hash('sha256', $content),
            'mime' => 'text/plain',
            'size' => strlen($content),
            'metadata' => ['title' => $this->faker->sentence()],
            'version' => 1,
            'status' => 'pending',
            'language' => 'en',
        ];
    }
}
