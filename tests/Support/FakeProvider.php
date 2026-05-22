<?php
/**
 * A scripted BuildProvider test double.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Tests\Support;

use CAW\PluginBuilder\Agent\BuildHandle;
use CAW\PluginBuilder\Agent\BuildProgress;
use CAW\PluginBuilder\Agent\BuildProvider;
use CAW\PluginBuilder\Agent\BuildSpec;
use CAW\PluginBuilder\Agent\ProviderException;

/**
 * A BuildProvider whose responses are scripted, used to drive the poller
 * through its state machine without touching the real Managed Agents API.
 *
 * Its existence also exercises the provider abstraction: the poller talks only
 * to the BuildProvider interface, so a completely different provider slots in.
 */
final class FakeProvider implements BuildProvider {

	public int $start_calls  = 0;
	public int $poll_calls   = 0;
	public int $cancel_calls = 0;

	/**
	 * @param BuildProgress|null $poll_result Progress to return from poll().
	 * @param bool               $fail_start  Whether start() should throw.
	 */
	public function __construct(
		private ?BuildProgress $poll_result = null,
		private bool $fail_start = false
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'fake';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Fake Provider';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function start( BuildSpec $spec ): BuildHandle {
		++$this->start_calls;
		if ( $this->fail_start ) {
			throw new ProviderException( 'Scripted start failure.', false );
		}
		return new BuildHandle( 'fake', [ 'session_id' => 'fake-session-' . $spec->slug() ] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function poll( BuildHandle $handle ): BuildProgress {
		++$this->poll_calls;
		return $this->poll_result ?? BuildProgress::running( 'Scripted: still running.' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function cancel( BuildHandle $handle ): void {
		++$this->cancel_calls;
	}
}
