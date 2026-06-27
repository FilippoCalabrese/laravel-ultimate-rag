<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Eloquent\EmbeddableCompiler;
use Sellinnate\RagEngine\Eloquent\EmbeddableDefinition;
use Sellinnate\RagEngine\Exceptions\RagException;

/**
 * A minimal, non-Eloquent embeddable for compiler unit tests.
 */
final class FakeEmbeddable implements Embeddable
{
    /** @var list<array{label: string, value: string|null}> */
    public array $fields = [];

    /** @var list<array{relation: string, embeddable: Embeddable}> */
    public array $children = [];

    /** @var array<string, mixed> */
    public array $meta = [];

    public ?string $keyOverride = null;

    public function __construct(public string $id = '') {}

    public function field(string $label, ?string $value): self
    {
        $this->fields[] = ['label' => $label, 'value' => $value];

        return $this;
    }

    public function child(Embeddable $embeddable, string $relation = ''): self
    {
        $this->children[] = ['relation' => $relation, 'embeddable' => $embeddable];

        return $this;
    }

    public function embeddableKey(): string
    {
        return 'fake:'.$this->id;
    }

    public function toEmbeddable(): EmbeddableDefinition
    {
        $definition = EmbeddableDefinition::make();

        foreach ($this->fields as $field) {
            $definition->add($field['label'], $field['value']);
        }

        foreach ($this->children as $child) {
            $definition->include($child['embeddable'], $child['relation']);
        }

        if ($this->meta !== []) {
            $definition->metadata($this->meta);
        }

        if ($this->keyOverride !== null) {
            $definition->documentKey($this->keyOverride);
        }

        return $definition;
    }
}

it('composes labelled fields into a single document', function () {
    $root = (new FakeEmbeddable('1'))
        ->field('Title', 'Solar Power')
        ->field('Body', 'Panels convert sunlight.');

    $compiled = (new EmbeddableCompiler)->compile($root);

    expect($compiled->content)->toContain('[Title]')
        ->and($compiled->content)->toContain('Solar Power')
        ->and($compiled->content)->toContain('[Body]')
        ->and($compiled->content)->toContain('Panels convert sunlight.')
        ->and($compiled->documentKey)->toBe('fake:1')
        ->and($compiled->includedKeys)->toBe([]);
});

it('drops blank and null fields', function () {
    $root = (new FakeEmbeddable('1'))
        ->field('Title', 'Kept')
        ->field('Empty', '')
        ->field('Null', null)
        ->field('Whitespace', "   \n  ");

    $compiled = (new EmbeddableCompiler)->compile($root);

    expect($compiled->content)->toBe("[Title]\nKept");
});

it('recursively composes related embeddables and records their keys', function () {
    $author = (new FakeEmbeddable('a1'))->field('Name', 'Jane');
    $comment = (new FakeEmbeddable('c1'))->field('Body', 'Great read');

    $root = (new FakeEmbeddable('p1'))
        ->field('Title', 'Post')
        ->child($author, 'author')
        ->child($comment, 'comments');

    $compiled = (new EmbeddableCompiler)->compile($root);

    expect($compiled->content)->toContain('[author]')
        ->and($compiled->content)->toContain('Jane')
        ->and($compiled->content)->toContain('[comments]')
        ->and($compiled->content)->toContain('Great read')
        ->and($compiled->includedKeys)->toEqualCanonicalizing(['fake:a1', 'fake:c1']);
});

it('honours the max recursion depth', function () {
    $deep = (new FakeEmbeddable('d3'))->field('Body', 'TOO_DEEP');
    $mid = (new FakeEmbeddable('d2'))->field('Body', 'level2')->child($deep, 'next');
    $root = (new FakeEmbeddable('d1'))->field('Body', 'level1')->child($mid, 'next');

    // maxDepth 1: root (0) renders its first level of children (1), but their
    // children (depth 2) are beyond the limit.
    $compiled = (new EmbeddableCompiler(maxDepth: 1))->compile($root);

    expect($compiled->content)->toContain('level1')
        ->and($compiled->content)->toContain('level2')
        ->and($compiled->content)->not->toContain('TOO_DEEP')
        ->and($compiled->includedKeys)->toBe(['fake:d2']);
});

it('terminates on a cycle without infinite recursion', function () {
    $a = (new FakeEmbeddable('a'))->field('Body', 'A-content');
    $b = (new FakeEmbeddable('b'))->field('Body', 'B-content');
    $a->child($b, 'b');
    $b->child($a, 'a'); // cycle: a -> b -> a

    $compiled = (new EmbeddableCompiler(maxDepth: 10))->compile($a);

    // Both render once; the back-reference to A is pruned by the cycle guard.
    expect(substr_count($compiled->content, 'A-content'))->toBe(1)
        ->and(substr_count($compiled->content, 'B-content'))->toBe(1)
        ->and($compiled->includedKeys)->toBe(['fake:b']);
});

it('carries metadata, options and a document-key override', function () {
    $root = (new FakeEmbeddable('1'))->field('Title', 'X');
    $root->meta = ['status' => 'published'];
    $root->keyOverride = 'custom:key';

    $compiled = (new EmbeddableCompiler)->compile($root);

    expect($compiled->metadata)->toBe(['status' => 'published'])
        ->and($compiled->documentKey)->toBe('custom:key');
});

it('reports an all-blank embeddable as empty', function () {
    $root = (new FakeEmbeddable('1'))->field('Title', '')->field('Body', null);

    $compiled = (new EmbeddableCompiler)->compile($root);

    expect($compiled->isEmpty())->toBeTrue()
        ->and($compiled->content)->toBe('');
});

it('exposes text(), options() and getters on the definition', function () {
    $definition = EmbeddableDefinition::make()
        ->text('Unlabelled block')
        ->add('L', 'V')
        ->options(['strategy' => 'sentence']);

    expect($definition->optionsArray())->toBe(['strategy' => 'sentence'])
        ->and($definition->isEmpty())->toBeFalse()
        ->and($definition->parts())->toHaveCount(2);

    $compiled = (new EmbeddableCompiler)->compile(new class($definition) implements Embeddable
    {
        public function __construct(private EmbeddableDefinition $definition) {}

        public function toEmbeddable(): EmbeddableDefinition
        {
            return $this->definition;
        }
    });

    expect($compiled->content)->toContain('Unlabelled block')
        ->and($compiled->options)->toBe(['strategy' => 'sentence']);
});

it('stringifies scalar field values', function () {
    $parts = EmbeddableDefinition::make()
        ->add('N', 42)
        ->add('B', true)
        ->add('Zero', false)
        ->parts();

    expect($parts[0]['value'])->toBe('42')
        ->and($parts[1]['value'])->toBe('1')
        ->and($parts[2]['value'])->toBe('0');
});

it('throws when a file field is declared but no file resolver is configured', function () {
    $embeddable = new class implements Embeddable
    {
        public function toEmbeddable(): EmbeddableDefinition
        {
            return EmbeddableDefinition::make()->add('Title', 'X')->addFile('Doc', '/tmp/x.pdf');
        }
    };

    (new EmbeddableCompiler)->compile($embeddable);
})->throws(RagException::class, 'file resolver');

it('produces a stable checksum that changes with content', function () {
    $a = (new EmbeddableCompiler)->compile((new FakeEmbeddable('1'))->field('Title', 'One'));
    $b = (new EmbeddableCompiler)->compile((new FakeEmbeddable('1'))->field('Title', 'One'));
    $c = (new EmbeddableCompiler)->compile((new FakeEmbeddable('1'))->field('Title', 'Two'));

    expect($a->checksum())->toBe($b->checksum())
        ->and($a->checksum())->not->toBe($c->checksum());
});
