<?php declare(strict_types=1);

namespace Rush\Tool\Schema\Command;

use Rush\Contract\CommandInterface;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Tool\Schema\Config;
use Rush\Tool\Schema\Config\DatabaseConfig;


abstract class CommandAbstract implements CommandInterface
{
    private ?Config $config = null;


    public function __construct(
        protected string $projectRoot,
    ) {
    }


    public function usage(): string
    {
        $parts = ['rush', $this->name()];

        foreach ($this->options() as $option) {
            $label = '--'. $option->name;

            if ($option->takesValue) {
                $label .= '=<'. $option->valueName .'>';
            }

            $parts[] = $option->required ? $label : '['. $label .']';
        }

        return implode(' ', $parts);
    }


    /** @return list<Option> */
    public function options(): array
    {
        return $this->commandOptions();
    }


    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [];
    }


    protected function config(): Config
    {
        return $this->config ??= Config::load($this->projectRoot);
    }


    protected function database(InputInterface $input): DatabaseConfig
    {
        $name = $input->string('database') ?? 'default';

        return $this->config()->database($name);
    }
}
