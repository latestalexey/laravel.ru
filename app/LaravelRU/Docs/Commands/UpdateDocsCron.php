<?php namespace LaravelRU\Docs\Commands;

use Carbon\Carbon;
use Config;
use Github\Client as GithubClient;
use Github\Exception\RuntimeException;
use Guzzle\Http\Client as GuzzleClient;
use Indatus\Dispatcher\Drivers\Cron\Scheduler;
use Indatus\Dispatcher\Scheduling\Schedulable;
use Indatus\Dispatcher\Scheduling\ScheduledCommand;
use LaravelRU\Access\Models\Role;
use LaravelRU\Docs\Models\Documentation;
use LaravelRU\Github\GithubRepo;
use Laravelrus\LocalizedCarbon\Models\Eloquent;
use Log;
use Mail;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use LaravelRU\Core\Models\Version as FrameworkVersion;

class UpdateDocsCron extends ScheduledCommand {

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'su:update_docs';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Update russian docs from translated github repo.';

	/**
	 * @var \LaravelRU\Github\GithubRepo
	 */
	private $githubTranslated;

	/**
	 * @var \LaravelRU\Github\GithubRepo
	 */
	private $githubOriginal;

	/**
	 * Create a new command instance.
	 */
	public function __construct()
	{
		parent::__construct();

		$this->githubTranslated = new GithubRepo(
			Config::get('laravel.translated_docs.user'),
			Config::get('laravel.translated_docs.repository')
		);

		$this->githubOriginal = new GithubRepo(
			Config::get('laravel.original_docs.user'),
			Config::get('laravel.original_docs.repository')
		);
	}

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function fire()
	{
		Eloquent::unguard();
		date_default_timezone_set('UTC');
		Log::info('su:update_docs begin');

		$updatedOriginalDocs = [];

		$forceupdate = $this->argument('force');
		$force_branch = $this->option('branch');
		$force_file = $this->option('file');
		$isPretend = $this->option('pretend');

		if ($force_branch)
		{
			if ($force_branch == "master")
			{
				$all_versions[] = FrameworkVersion::master()->first();
			}
			else
			{
				$all_versions[] = FrameworkVersion::whereNumber($force_branch)->first();
			}
		}
		else
		{
			$all_versions = FrameworkVersion::all();
		}

		/** @var FrameworkVersion $v */
		foreach ($all_versions as $v)
		{
			$id = $v->id;
			$version = $v->number_alias;

			$this->info("Process branch $version");

			if ($force_file)
			{
				if ($forceupdate)
				{
					Documentation::version($v)->page($force_file)->delete();
					$this->info("clear exist file $force_file !");
				}

				$lines = ["[File](/docs/$version/$force_file)"];
			}
			else
			{
				if ($forceupdate)
				{
					Documentation::version($v)->delete();
					$this->info("clear exist {$version} docs!");
				}

				$this->line('Fetch documentation menu');

				// В случае ошибки при получении документации продолжить цикл.
				try
				{
					$content = $this->githubTranslated->getFile($version, 'documentation.md');
				}
				catch (RuntimeException $e)
				{
					continue;
				}

				$lines = explode("\n", $content);
				$lines[] = "[Menu](/docs/$version/documentation)";
			}

			$matches = [];
			foreach ($lines as $line)
			{
				try
				{
					preg_match('/\(\/docs\/(.*?)\/(.*?)\)/im', $line, $matches);

					if (isset($matches[2]))
					{
						$name = $matches[2];
						$filename = $name . '.md';
						$this->line('');
						$this->line("Fetch {$filename}...");
						$this->line(' get last translated commit');
						$commit = $this->githubTranslated->getLastCommit($version, $filename);

						if ( ! is_null($commit))
						{
							$last_commit_id = $commit['sha'];
							$last_commit_at = Carbon::createFromTimestampUTC(
								strtotime($commit['commit']['committer']['date'])
							);
							$this->line(' get file');
							$content = $this->githubTranslated->getFile($version, $filename, $last_commit_id);
							if ( ! is_null($content))
							{
								preg_match('/git (.*?)$/m', $content, $matches);
								$last_original_commit_id = array_get($matches, '1');


								$this->line(" get last translated original commit $last_original_commit_id");
								$count_ahead = 0;
								try
								{
									$original_commit = $this->githubOriginal->getCommit($last_original_commit_id);
									$current_original_commit = "";
									$last_original_commit_at = Carbon::createFromTimestampUTC(strtotime($original_commit['commit']['committer']['date']));

									// Считаем сколько коммитов прошло с момента перевода
									$this->line(' get current original commit');
									$after_last_original_commit_at = $last_original_commit_at;
									$after_last_original_commit_at = $after_last_original_commit_at->addSecond();
									$original_commits = $this->githubOriginal->getCommits($version, $filename, $after_last_original_commit_at);
									$count_ahead = count($original_commits);
									$current_original_commit = $this->githubOriginal->getLastCommit($version, $filename);
									$current_original_commit_id = $current_original_commit['sha'];
									$current_original_commit_at = Carbon::createFromTimestampUTC(
										strtotime(
											$current_original_commit['commit']['committer']['date']
										)
									);

								}
								catch (RuntimeException $e)
								{
									// Оригинальный файл не найден
									$last_original_commit_at = null;
									$current_original_commit_id = "";
									$current_original_commit_at = null;
								}


								$content = preg_replace('/git(.*?)(\n*?)---(\n*?)/', "", $content);
								preg_match('/#(.*?)$/m', $content, $matches);
								$title = trim(array_get($matches, '1'));
								$page = Documentation::version($v)->page($name)->first();
								if ($page)
								{
									if ($current_original_commit_id != $page->current_original_commit)
									{
										// Обновилась оригинальная дока
										$page->current_original_commit = $current_original_commit_id;
										$page->current_original_commit_at = $current_original_commit_at;
										$page->original_commits_ahead = $count_ahead;
										if ( ! $isPretend)
										{
											$page->save();
										}
										$this->info("Detected changes in original $version/$filename - commit $current_original_commit_id. Requires new translation.");
										$updatedOriginalDocs[] = ['name' => "$version/$filename", 'commit' => $current_original_commit_id];
									}

									if ($last_commit_id != $page->last_commit)
									{
										// Обновился перевод
										$page->last_commit = $last_commit_id;
										$page->last_commit_at = $last_commit_at;
										$page->last_original_commit = $last_original_commit_id;
										$page->last_original_commit_at = $last_original_commit_at;
										$page->original_commits_ahead = $count_ahead;
										$page->title = $title;
										$page->text = $content;
										if ( ! $isPretend)
										{
											$page->save();
										}
										$this->info("$version/$filename updated. Commit $last_commit_id. Last original commit $last_original_commit_id.");
									}
								}
								else
								{
									if ( ! $isPretend)
									{
										Documentation::create([
											'version_id' => $id,
											'page' => $name,
											'title' => $title,
											'last_commit' => $last_commit_id,
											'last_commit_at' => $last_commit_at,
											'last_original_commit' => $last_original_commit_id,
											'last_original_commit_at' => $last_original_commit_at,
											'current_original_commit' => $current_original_commit_id,
											'current_original_commit_at' => $current_original_commit_at,
											'original_commits_ahead' => $count_ahead,
											'text' => $content
										]);
									}

									$this->info("Translate for $version/$filename created, commit $last_commit_id. Translated from original commit $last_original_commit_id.");
								}

							}
						}
					}
				}
				catch (RuntimeException $e)
				{
					Log::error('su:update_docs \Github\Exception\RuntimeException ' . $e->getMessage());
					die();
				}
			}
		}

		if (count($updatedOriginalDocs) > 0)
		{

			$librarians = Role::whereName("librarian")->users;
			foreach ($librarians as $user)
			{
				Mail::queue('emails/librarian/changes', ['updatedOriginalDocs' => $updatedOriginalDocs], function ($message) use ($user)
				{
					$message->from('postmaster@sharedstation.net');
					$message->to($user->email);
					$message->subject('Изменения в оригинальной документации Laravel');
				});
			}

		}

		Log::info('su:update_docs   end');
	}

	/**
	 * Get the console command arguments.
	 *
	 * @return array
	 */
	protected function getArguments()
	{
		return [
			['force', InputArgument::OPTIONAL, 'Delete all docs and replace by github data.'],
		];
	}

	protected function getOptions()
	{
		return [
			['branch', null, InputOption::VALUE_REQUIRED, 'Name of branch for update.', null],
			['file', null, InputOption::VALUE_REQUIRED, 'Filename for update.', null],
			['pretend', null, InputOption::VALUE_NONE, 'Emulation only, without DB changes.', null],
		];
	}

	/**
	 * When a command should run
	 *
	 * @param Schedulable|Scheduler $scheduler
	 * @return \Indatus\Dispatcher\Scheduling\Schedulable
	 */
	public function schedule(Schedulable $scheduler)
	{
		return $scheduler->everyHours(1)->minutes(50);
	}

}
