<?php

namespace App\Services\Gateway\Data;

/** One setting a gateway needs. The super admin's configure form is built from these, so a new gateway needs no new form. */
final readonly class CredentialField
{
    public function __construct(
        public string $name,
        public string $label,
        public string $type = 'text', // text, password, textarea, url, select, boolean
        public bool $required = true,
        public bool $isSecret = false,
        public ?string $help = null,
        /** @var array<string, string> value => label, for a select */
        public array $options = [],
    ) {}

    public static function text(string $name, string $label, bool $required = true, ?string $help = null): self
    {
        return new self($name, $label, 'text', $required, false, $help);
    }

    public static function secret(string $name, string $label, bool $required = true, ?string $help = null): self
    {
        return new self($name, $label, 'password', $required, true, $help);
    }

    public static function key(string $name, string $label, bool $required = true, ?string $help = null): self
    {
        return new self($name, $label, 'textarea', $required, true, $help);
    }

    public static function url(string $name, string $label, bool $required = true, ?string $help = null): self
    {
        return new self($name, $label, 'url', $required, false, $help);
    }

    public static function boolean(string $name, string $label, ?string $help = null): self
    {
        return new self($name, $label, 'boolean', false, false, $help);
    }

    public static function longText(string $name, string $label, bool $required = false, ?string $help = null): self
    {
        return new self($name, $label, 'textarea', $required, false, $help);
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'label' => $this->label, 'type' => $this->type, 'required' => $this->required, 'is_secret' => $this->isSecret, 'help' => $this->help, 'options' => $this->options];
    }
}
