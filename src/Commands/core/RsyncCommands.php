<?php
namespace Drush\Commands\core;

use Consolidation\AnnotatedCommand\CommandData;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Drush\Exceptions\UserAbortException;
use Drush\SiteAlias\HostPath;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;
use Drush\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Backend\BackendPathEvaluator;
use Robo\Contract\ConfigAwareInterface;
use Robo\Common\ConfigAwareTrait;
use Drush\Config\ConfigLocator;
use Symfony\Component\Console\Event\ConsoleCommandEvent;

class RsyncCommands extends DrushCommands implements SiteAliasManagerAwareInterface, ConfigAwareInterface
{
    use SiteAliasManagerAwareTrait;
    use ConfigAwareTrait;

    /**
     * These are arguments after the aliases and paths have been evaluated.
     * @see validate().
     */
    /** @var HostPath */
    public $sourceEvaluatedPath;
    /** @var HostPath */
    public $destinationEvaluatedPath;
    /** @var BackendPathEvaluator */
    protected $pathEvaluator;

    public function __construct()
    {
        // TODO: once the BackendInvoke service exists, inject it here
        // and use it to get the path evaluator
        $this->pathEvaluator = new BackendPathEvaluator();
    }

    /**
     * Rsync Drupal code or files to/from another server using ssh.
     *
     * @command core:rsync
     * @param $source A site alias and optional path. See rsync documentation and example.aliases.yml.
     * @param $destination A site alias and optional path. See rsync documentation and example.aliases.config.yml.',
     * @param $extra Additional parameters after the ssh statement.
     * @optionset_ssh
     * @option exclude-paths List of paths to exclude, seperated by : (Unix-based systems) or ; (Windows).
     * @option include-paths List of paths to include, seperated by : (Unix-based systems) or ; (Windows).
     * @option mode The unary flags to pass to rsync; --mode=rultz implies rsync -rultz.  Default is -akz.
     * @usage drush rsync @dev @stage
     *   Rsync Drupal root from Drush alias dev to the alias stage.
     * @usage drush rsync ./ @stage:%files/img
     *   Rsync all files in the current directory to the 'img' directory in the file storage folder on the Drush alias stage.
     * @usage drush rsync @dev @stage -- --exclude=*.sql --delete
     *   Rsync Drupal root from the Drush alias dev to the alias stage, excluding all .sql files and delete all files on the destination that are no longer on the source.
     * @usage drush rsync @dev @stage --ssh-options="-o StrictHostKeyChecking=no" -- --delete
     *   Customize how rsync connects with remote host via SSH. rsync options like --delete are placed after a --.
     * @aliases rsync,core-rsync
     * @topics docs:aliases
     */
    public function rsync($source, $destination, array $extra, $options = ['exclude-paths' => self::REQ, 'include-paths' => self::REQ, 'mode' => 'akz'])
    {
        // Prompt for confirmation. This is destructive.
        if (!\Drush\Drush::simulate()) {
            $this->output()->writeln(dt("You will delete files in !target and replace with data from !source", array('!source' => $this->sourceEvaluatedPath->fullyQualifiedPathPreservingTrailingSlash(), '!target' => $this->destinationEvaluatedPath->fullyQualifiedPath())));
            if (!$this->io()->confirm(dt('Do you want to continue?'))) {
                throw new UserAbortException();
            }
        }

        $rsync_options = $this->rsyncOptions($options);
        $parameters = array_merge([$rsync_options], $extra);
        $parameters[] = $this->sourceEvaluatedPath->fullyQualifiedPathPreservingTrailingSlash();
        $parameters[] = $this->destinationEvaluatedPath->fullyQualifiedPath();

        $ssh_options = Drush::config()->get('ssh.options', '');
        $exec = "rsync -e 'ssh $ssh_options'". ' '. implode(' ', array_filter($parameters));
        $exec_result = drush_op_system($exec);

        if ($exec_result == 0) {
            drush_backend_set_result($this->destinationEvaluatedPath->fullyQualifiedPath());
        } else {
            throw new \Exception(dt("Could not rsync from !source to !dest", array('!source' => $this->sourceEvaluatedPath->fullyQualifiedPathPreservingTrailingSlash(), '!dest' => $this->destinationEvaluatedPath->fullyQualifiedPath())));
        }
    }

    public function rsyncOptions($options)
    {
        $verbose = $paths = '';
        // Process --include-paths and --exclude-paths options the same way
        foreach (array('include', 'exclude') as $include_exclude) {
            // Get the option --include-paths or --exclude-paths and explode to an array of paths
            // that we will translate into an --include or --exclude option to pass to rsync
            $inc_ex_path = explode(PATH_SEPARATOR, @$options[$include_exclude . '-paths']);
            foreach ($inc_ex_path as $one_path_to_inc_ex) {
                if (!empty($one_path_to_inc_ex)) {
                    $paths .= ' --' . $include_exclude . '="' . $one_path_to_inc_ex . '"';
                }
            }
        }

        $mode = '-'. $options['mode'];
        if ($this->output()->isVerbose()) {
            $mode .= 'v';
            $verbose = ' --stats --progress';
        }

        return implode(' ', array_filter([$mode, $verbose, $paths]));
    }

    /**
     * Evaluate the path aliases in the source and destination
     * parameters. We do this in the pre-command-event so that
     * we can set up the configuration object to include options
     * from the source and target aliases, if any, so that these
     * values may participate in configuration injection.
     *
     * @hook command-event core:rsync
     * @param ConsoleCommandEvent $event
     * @throws \Exception
     * @return void
     */
    public function preCommandEvent(ConsoleCommandEvent $event)
    {
        $input = $event->getInput();
        $destination = $input->getArgument('destination');
        $source = $input->getArgument('source');

        $manager = $this->siteAliasManager();
        $this->sourceEvaluatedPath = HostPath::create($manager, $source);
        $this->destinationEvaluatedPath = HostPath::create($manager, $destination);

        $this->pathEvaluator->evaluate($this->sourceEvaluatedPath);
        $this->pathEvaluator->evaluate($this->destinationEvaluatedPath);

        // The Drush configuration object is a ConfigOverlay; fetch the alias
        // context, that already has the options et. al. from the
        // site-selection alias ('drush @site rsync ...'), @self.
        $aliasConfigContext = $this->getConfig()->getContext(ConfigLocator::ALIAS_CONTEXT);

        $this->injectAliasOptions($aliasConfigContext, $this->sourceEvaluatedPath->getAliasRecord(), 'source');
        $this->injectAliasOptions($aliasConfigContext, $this->destinationEvaluatedPath->getAliasRecord(), 'target');
    }

    /**
     * Copy options from the source and destination aliases into the
     * alias context.
     */
    protected function injectAliasOptions($aliasConfigContext, $aliasRecord, $parameterSpecificOptions)
    {
        if (empty($aliasRecord)) {
            return;
        }
        $aliasData = $aliasRecord->export();
        $aliasOptions = [
            'options' => $aliasData['options'],
            'command' => $aliasData['command'],
        ];
        if (isset($aliasData[$parameterSpecificOptions])) {
            $aliasOptions = self::arrayMergeRecursiveDistinct($aliasOptions, $aliasData[$parameterSpecificOptions]);
        }
        // 'import' is supposed to merge, but in fact it overwrites.
        // We will therefore manually merge as a workaround.
       // print "starting alias values: " . var_export($aliasConfigContext->export(), true) . "\n";
       // print "merge into $parameterSpecificOptions: " . var_export($aliasOptions, true) . "\n";
        $merged = self::arrayMergeRecursiveDistinct($aliasOptions, $aliasConfigContext->export());
        $aliasConfigContext->import($merged);
       // print "Result: " . var_export($aliasConfigContext, true) . "\n";
    }

    /**
     * Validate that passed aliases are valid.
     *
     * @hook validate core-rsync
     * @param \Consolidation\AnnotatedCommand\CommandData $commandData
     * @throws \Exception
     * @return void
     */
    public function validate(CommandData $commandData)
    {
        if ($this->sourceEvaluatedPath->isRemote() && $this->destinationEvaluatedPath->isRemote()) {
            $msg = dt("Cannot specify two remote aliases. Instead, use one of the following alternate options:\n\n    `drush {source} rsync @self {target}`\n    `drush {source} rsync @self {fulltarget}\n\nUse the second form if the site alias definitions are not available at {source}.", array('source' => $source, 'target' => $destination, 'fulltarget' => $this->destinationEvaluatedPath->fullyQualifiedPath()));
            throw new \Exception($msg);
        }
    }

    /**
     * Merges arrays recursively while preserving. TODO: Factor this into a reusable utility class
     *
     * @param array $array1
     * @param array $array2
     *
     * @return array
     *
     * @see http://php.net/manual/en/function.array-merge-recursive.php#92195
     * @see https://github.com/grasmash/bolt/blob/robo-rebase/src/Robo/Common/ArrayManipulator.php#L22
     */
    protected static function arrayMergeRecursiveDistinct(
        array &$array1,
        array &$array2
    ) {
        $merged = $array1;
        foreach ($array2 as $key => &$value) {
            $merged[$key] = self::mergeRecursiveValue($merged, $key, $value);
        }
        return $merged;
    }

    /**
     * Process the value in an arrayMergeRecursiveDistinct - make a recursive
     * call if needed.
     */
    private static function mergeRecursiveValue(&$merged, $key, $value)
    {
        if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
            return self::arrayMergeRecursiveDistinct($merged[$key], $value);
        }
        return $value;
    }

}
