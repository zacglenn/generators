<?php

namespace Laracademy\Generators\Commands;

use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Illuminate\Console\Command;

class ModelFromTableCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate:modelfromtable
                            {--table= : a single table or a list of tables separated by a comma (,)}
                            {--schema= : what schema to use}
                            {--connection= : database connection to use, leave off and it will use the .env connection}
                            {--debug= : turns on debugging}
                            {--folder= : by default models are stored in app, but you can change that}
                            {--filename= : generated by table name, but you can customize this further}
                            {--namespace= : by default the namespace that will be applied to all models is App}
                            {--singular : class name and class file name singular or plural}
                            {--all= : run for all tables - DEPRECATED}
                            {--overwrite= : overwrite model(s) if exists}
                            {--timestamps= : whether to timestamp or not}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate models for the given tables based on their columns';

    private $db;
    private $options;
    private $startTime;
    private $delimiter;
    private $stubConnection;

    private $modelPath;
    private $modelStub;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->startTime = microtime(true);

        parent::__construct();

        $this->modelPath = (app()->version() > '8')? app()->path('Models') : app()->path();

        $this->options = [
            'connection' => '',
            'namespace'  => '',
            'table'      => '',
            'schema'     => '',
            'folder'     => $this->modelPath,
            'filename'   => '',
            'debug'      => false,
            'singular'   => false,
            'overwrite'  => false
        ];

        $this->delimiter = config('modelfromtable.delimiter', ', ');

        $this->modelStub = file_get_contents($this->getStub());
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->doComment('Starting Model Generate Command', true);

        $this->hydrateOptions();

        $this->db = DB::connection($this->options['connection']);
        $this->stubConnection = $this->getConnectionStub();

        $tables = [];
        $path = $this->options['folder'];
        $overwrite = $this->getOption('overwrite', false);
        $modelStub = file_get_contents($this->getStub());

        // figure out if we need to create a folder or not
        // NOTE: lambas will need to handle this themselves
        if (!is_callable($path) && $path != $this->modelPath) {
            if (!is_dir($path)) {
                mkdir($path);
            }
        }

        $tables = $this->getTables();

        // cycle through each table
        foreach ($tables as $table) {
            if (!$overwrite and file_exists($table['file']['path'])) {
                $this->doComment("Skipping file: {$table['file']['name']}");
                continue;
            }

            $this->doComment("Generating file: {$table['file']['name']}");

            $stub = $this->hydrateStub($table);

            // writing stub out
            $this->doComment("Writing model: {$table['file']['path']}", true);
            file_put_contents($table['file']['path'], $stub);
        }

        $this->info('Completed in ' . (number_format(microtime(true) - $this->startTime, 2)) . ' seconds');
    }

    public function describeTable($tableName)
    {
        $this->doComment('Retrieving column information for : '.$tableName);

        return $this->db->select($this->db->raw("describe `{$tableName}`"));
    }

    /**
     * hydrates the stub with table data
     *
     * @param string $stub  stub content
     * @param array  $table array (key => value)
     *
     * @return string stub content
     */
    public function hydrateStub($table)
    {
        // replace table
        $stub = $this->modelStub;

        $primaryKey = $table['primary'];

        // reset stub fields
        $stubDocBlock = $stubFillable = $stubHidden = $stubCast = $stubDate = '';
        
        $types = [];

        foreach ($table['columns'] as $column) {
            // fillable
            if ($column['field'] != $primaryKey) {
                $this->interpolate($stubFillable, "'{$column['field']}'");
            }

            // cast/date
            $type = strtolower($column['type']);
            $type = preg_replace("/\s.*$/", '', $type);
            preg_match_all("/^(\w*)\((?:(\d+)(?:,(\d+))*)\)/", $type, $matches);

            $columnType = isset($matches[1][0]) ? $matches[1][0] : $type;
            $columnLength = isset($matches[2][0]) ? $matches[2][0] : '';

            switch ($columnType) {
                case 'int':
                case 'tinyint':
                case 'boolean':
                case 'bool':
                    $castType = ($columnLength == 1) ? 'boolean' : 'int';
                    $types[$castType][] = $column['field'];

                    $this->interpolate($stubCast, "'{$column['field']}' => '$castType'");
                    break;
                case 'varchar':
                case 'text':
                case 'tinytext':
                case 'mediumtext':
                case 'longtext':
                    $types['string'][] = $column['field'];

                    $this->interpolate($stubCast, "'{$column['field']}' => 'string'");
                    break;
                case 'float':
                case 'double':
                    $types['float'][] = $column['field'];

                    $this->interpolate($stubCast, "'{$column['field']}' => '$columnType'");
                    break;
                case 'timestamp':
                    $types['int'][] = $column['field'];

                    $this->interpolate($stubCast, "'{$column['field']}' => '$columnType'");
                    $this->interpolate($stubDate, "'{$column['field']}'");
                    break;
                case 'datetime':
                    $types['DateTime'][] = $column['field'];

                    $this->interpolate($stubCast, "'{$column['field']}' => '$columnType'");
                    $this->interpolate($stubDate, "'{$column['field']}'");
                    break;
                case 'date':
                    $types['Date'][] = $column['field'];

                    $this->interpolate($stubCast, "'{$column['field']}' => '$columnType'");
                    $this->interpolate($stubDate, "'{$column['field']}'");
                    break;
            }
        }

        // generate doclines (doing this after the loop above so we can get the largest cast type and pad accordingly)
        $generateDocLine = (function (string $type, string $field, int $padding) { return str_pad("\n * @property {$type}", $padding, ' ') . "$$field";});
        $padding = array_reduce(array_keys($types), fn($carry, $x) => max($carry, strlen($x)), 0) + 15;

        foreach ($types as $type => $fields) {
            foreach($fields as $field) {
                $this->interpolate($stubDocBlock, $generateDocLine($type, $field, $padding), "");
            }
        }

        $timestamps = ($this->getOption('timestamps', false, true)) ? 'false' : 'true';

        // replace in stub
        $stub = str_replace('{{connection}}', $this->stubConnection, $stub);
        $stub = str_replace('{{class}}', $table['file']['class'], $stub);
        $stub = str_replace('{{docblock}}', $stubDocBlock, $stub);
        $stub = str_replace('{{table}}', $table['name'], $stub);
        $stub = str_replace('{{primaryKey}}', $primaryKey, $stub);
        $stub = str_replace('{{fillable}}', $stubFillable, $stub);
        $stub = str_replace('{{hidden}}', $stubHidden, $stub);
        $stub = str_replace('{{casts}}', $stubCast, $stub);
        $stub = str_replace('{{dates}}', $stubDate, $stub);
        $stub = str_replace('{{timestamps}}', $timestamps, $stub);
        $stub = str_replace('{{namespace}}', str_replace('/', '\\', $table['file']['namespace']), $stub);

        return $stub;
    }

    private function interpolate(string &$string, string $add, $delimiter = null)
    {
        $delimiter = $delimiter ?? $this->delimiter;
        $string .= (strlen($string) > 0 ? $delimiter : '').$add;
    }

    public function getConnectionStub()
    {
        return ($database = $this->options['connection'])
            ? "/**
     * The connection name for the model.
     *
     * @var string
     */
    protected \$connection = '{$database}';\n\n    "
            : '';
    }

    /**
     * returns the stub to use to generate the class.
     */
    public function getStub()
    {
        $this->doComment('loading model stub');

        return __DIR__.'/../stubs/model.stub';
    }

    /**
     * fills all the options that the user specified, overwriting defaults if necessary
     */
    public function hydrateOptions()
    {
        $this->options['debug'] = $this->getOption('debug', false, true);
        $this->options['connection'] = $this->getOption('connection', '');
        $this->options['folder'] = $this->getOption('folder', '');
        $this->options['filename'] = $this->getOption('filename', '');
        $this->options['namespace'] = $this->getOption('namespace', '');
        $this->options['schema'] = $this->getOption('schema', '');

        // if there is no folder specified and no namespace, set default namespaace
        if (!$this->options['folder'] && !$this->options['namespace']) {
            // assume default APP
            $this->options['namespace'] = 'App\Models';
        } else {
            // else, if no namespace but a folder is set, use that path
            if (!$this->options['namespace']) {
                if ($folder = $this->options['folder']) {
                    $this->options['namespace'] = $folder;
                }
            }
        }

        // finish setting up folder (if not a function)
        if (!is_callable($this->options['folder'])) {
            $this->options['folder'] = ($this->options['folder']) ? base_path($this->options['folder']) : $this->modelPath;
            // trim trailing slashes
            $this->options['folder'] = rtrim($this->options['folder'], '/');
        }

        // single or list of tables
        $this->options['table'] = $this->getOption('table', '');

        // class name and class file name singular/plural
        $this->options['singular'] = $this->getOption('singular', false, true);
    }

    /**
     * returns single option with priority being user input, then user config, then default
     */
    private function getOption(string $key, $default = null, bool $isBool = false)
    {
        if ($isBool) {
            $return = ($this->option($key))
                ? filter_var($this->option($key), FILTER_VALIDATE_BOOLEAN)
                : config("modelfromtable.{$key}", $default);
        } else {
            $return = $this->options[$key] = $this->option($key) ?? config("modelfromtable.{$key}", $default);
        }

        return $return;
    }

    /**
     * will add a comment to the screen if debug is on, or is over-ridden.
     */
    public function doComment($text, $overrideDebug = false)
    {
        if ($this->options['debug'] || $overrideDebug) {
            $this->comment($text);
        }
    }

    /**
     * will return an array of all table names.
     */
    public function getTables()
    {
        $this->doComment('Retrieving database tables');

        $whitelist = config('modelfromtable.whitelist', []);
        $blacklist = config('modelfromtable.blacklist', ['migrations']);

        if ($this->options['table']) {
            $whitelist = $whitelist + explode(',', $this->options['table']);
        }

        // mysql REGEXP behaves differently than fnmatch, so slightly modify operators
        $whitelistString = Str::replace('*', '.*', implode('|', $whitelist));
        $whitelistString = "($whitelistString)$";
        $blacklistString = Str::replace('*', '.*', implode('|', $blacklist));
        $blacklistString = "($blacklistString)$";

        // get all tables by default
        $query = $this->db
            ->query()
            ->select(['TABLE_NAME as name', 'COLUMN_NAME as field', 'COLUMN_TYPE as type'])
            ->selectRaw("IF(COLUMN_KEY = 'PRI', 1, 0) as isPrimary")
            ->from('INFORMATION_SCHEMA.COLUMNS')
            ->where('TABLE_NAME', 'REGEXP', $whitelistString)
            ->where('TABLE_NAME', 'NOT REGEXP', $blacklistString)
            ->orderBy('TABLE_NAME')
            ->orderBy('isPrimary', 'DESC');

        if ($this->options['schema']) {
            $query->where('TABLE_SCHEMA', $this->options['schema']);
        } else {
            $query->whereNotIn('TABLE_SCHEMA', ['information_schema', 'mysql', 'sys']);
        }

        $columns = $query->get();

        return $columns
            ->groupBy('name')
            ->mapWithKeys(fn($x, $tableName) => [
                $tableName => [
                    'name' => $tableName,
                    'columns' => $x->map(fn($y) => [
                        'field' => $y->field,
                        'type' => $y->type
                    ]),
                    'primary' => ($x[0]->isPrimary) ? $x[0]->field : null,
                    'file' => $this->getPath($tableName)
                ]
            ]);
    }
       

    private function getPath($tableName)
    {
        $path = $this->options['folder'];
        $filename = $this->options['filename'];
        $namespace = $this->options['namespace'];

        if (is_callable($filename)) {
            $filename = $filename($tableName);
        } elseif (!$filename) {
            // generate the file name for the model based on the table name
            $filename = Str::studly($tableName);
        }

        if ($this->options['singular']) {
            $filename = Str::singular($filename);
        }

        if (is_callable($path)) {
            $path = $path($tableName);
        }

        // allow config to apply a lambda to obtain non-ordinary namespace
        if (is_callable($namespace)) {
            $namespace = $namespace($path);
        }

        return [
            'class' => $filename,
            'name' => "{$filename}.php",
            'path' => "{$path}/{$filename}.php",
            'namespace' => $namespace
        ];
    }
}
