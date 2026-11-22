<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Form;

use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\TaskBoardBundle\Dto\TaskImportFormData;
use Nowo\TaskBoardBundle\Form\ConnectionLinkFormType;
use Nowo\TaskBoardBundle\Form\TaskImportFormType;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Yaml\Yaml;

use function dirname;
use function is_array;

/**
 * Symfony translates constraint messages (`message:`, `mimeTypesMessage:` ...) in the `validators` domain, whatever
 * `translation_domain` the form declares. A dotted key that lives only in `NowoTaskBoardBundle.<locale>.yaml` would reach
 * the user as raw text. (`buildViolation(...)->setTranslationDomain(...)` and `invalid_message` name their own domain.)
 */
#[CoversNothing]
final class ConstraintMessagesTest extends TestCase
{
    private const TRANSLATIONS = '/src/Resources/translations';

    /**
     * @return list<string>
     */
    private static function shippedLocales(): array
    {
        $locales = [];
        foreach (glob(dirname(__DIR__, 3) . self::TRANSLATIONS . '/NowoTaskBoardBundle.*.yaml') ?: [] as $file) {
            $locales[] = explode('.', basename($file), 3)[1];
        }
        sort($locales);

        return $locales;
    }

    /**
     * Dotted keys passed as a literal to a constraint option named `message` / `*Message`, in any file under src/
     * (`invalid_message` is a form option translated in the form's own domain, so it is left out; so is src/Controller/).
     *
     * @return list<string>
     */
    public static function dottedConstraintMessages(): array
    {
        $found = [];
        $files = new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src')), '/\.php$/');
        foreach ($files as $file) {
            // Controllers name their own flash / JSON `message:` payloads: they are not constraint options.
            if (str_contains((string) $file, '/src/Controller/')) {
                continue;
            }
            $source = (string) file_get_contents((string) $file);
            if (preg_match_all('/(?<!invalid_)[mM]essage\w*\s*(?::|=>)\s*\'([a-z_0-9]+(?:\.[a-z_0-9]+)+)\'/', $source, $m) > 0) {
                array_push($found, ...$m[1]);
            }
        }

        return array_values(array_unique($found));
    }

    public function testEveryDottedConstraintMessageExistsInValidatorsForEveryShippedLocale(): void
    {
        $messages = self::dottedConstraintMessages();
        self::assertContains('task_board.import.form.file_invalid', $messages);
        self::assertContains('task_board.sync.browser.pick_list', $messages);

        $locales = self::shippedLocales();
        self::assertCount(7, $locales);
        foreach ($locales as $locale) {
            $file = dirname(__DIR__, 3) . self::TRANSLATIONS . '/validators.' . $locale . '.yaml';
            self::assertFileExists($file, $locale);
            $flat = self::flatten(Yaml::parseFile($file));

            foreach ($messages as $key) {
                self::assertArrayHasKey($key, $flat, sprintf('%s missing in validators.%s.yaml', $key, $locale));
                self::assertNotSame('', trim((string) $flat[$key]), $key . ' ' . $locale);
            }
        }
    }

    public function testBadImportFileAndEmptyListShowTranslatedTextInEveryShippedLocale(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tb');
        file_put_contents($tmp, "<?php echo 1;\n");

        try {
            foreach (self::shippedLocales() as $locale) {
                $factory = $this->factory($locale);

                $import = $factory->create(TaskImportFormType::class, new TaskImportFormData());
                $import->submit(['source' => 'clickup_csv', 'file' => new UploadedFile($tmp, 'export.php', 'application/x-php', null, true)]);
                $messages = $this->messages($import);
                self::assertNotSame([], $messages, $locale . ' import');

                $link = $factory->createNamed(ConnectionLinkFormType::NAME, ConnectionLinkFormType::class);
                $link->submit(['provider' => 'p', 'connection' => 'c', 'container' => '']);
                $messages = array_merge($messages, $this->messages($link));

                foreach ($messages as $message) {
                    self::assertStringNotContainsString('task_board.', $message, $locale);
                }
            }
        } finally {
            unlink($tmp);
        }
    }

    /**
     * @return list<string>
     */
    private function messages(\Symfony\Component\Form\FormInterface $form): array
    {
        $out = [];
        foreach ($form->getErrors(true) as $error) {
            $out[] = $error->getMessage();
        }

        return $out;
    }

    private function factory(string $locale): FormFactoryInterface
    {
        // The way FrameworkBundle wires it: every catalogue in its own domain, constraint messages in `validators`.
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['NowoTaskBoardBundle', 'validators'] as $domain) {
            $file = dirname(__DIR__, 3) . self::TRANSLATIONS . '/' . $domain . '.' . $locale . '.yaml';
            if (is_file($file)) {
                $translator->addResource('yaml', $file, $locale, $domain);
            }
        }
        $validator = Validation::createValidatorBuilder()->setTranslator($translator)->setTranslationDomain('validators')->getValidator();

        $profile = ['translation_domain' => 'NowoTaskBoardBundle', 'defaults' => ['attr' => [], 'row_attr' => []], 'field_types' => []];
        $merger  = new FormOptionsMerger(['default' => $profile, 'task_board' => $profile], 'default', new ConstraintDefinitionFactory());
        $types   = [new TaskImportFormType(), new ConnectionLinkFormType()];
        foreach ($types as $type) {
            $type->setFormOptionsMerger($merger);
        }

        return Forms::createFormFactoryBuilder()->addExtension(new ValidatorExtension($validator))->addTypes($types)->getFormFactory();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $out += self::flatten($v, $prefix . $k . '.');
            } else {
                $out[$prefix . $k] = $v;
            }
        }

        return $out;
    }
}
