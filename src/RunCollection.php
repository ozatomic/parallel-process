<?php

namespace Graze\ParallelProcess;

use Exception;
use Graze\DataStructure\Collection\Collection;
use Graze\ParallelProcess\Event\EventDispatcherTrait;
use Graze\ParallelProcess\Event\RunEvent;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Throwable;

class RunCollection extends Collection implements RunInterface, PoolInterface
{
    const STATE_NOT_STARTED = 0;
    const STATE_RUNNING     = 1;
    const STATE_NOT_RUNNING = 2;

    use EventDispatcherTrait;

    /** @var Pool */
    private $pool;
    /** @var RunInterface[] */
    protected $items = [];
    /** @var RunInterface[] */
    private $complete = [];
    /** @var int */
    private $state = self::STATE_NOT_STARTED;
    /** @var Exception[]|Throwable[] */
    private $exceptions;
    /** @var array */
    private $tags;
    /** @var float */
    private $started = 0.0;
    /** @var float */
    private $finished = 0.0;
    /** @var float */
    private $priority;

    /**
     * RunCollection constructor.
     *
     * @param Pool           $pool
     * @param RunInterface[] $runs
     * @param array          $tags
     * @param float          $priority
     */
    public function __construct(Pool $pool, array $runs, array $tags = [], $priority = 1.0)
    {
        parent::__construct([]);

        $this->pool = $pool;
        $this->tags = $tags;

        array_map([$this, 'add'], $runs);
        $this->priority = $priority;
    }

    /**
     * @param RunInterface|Process $item
     * @param array                $tags
     *
     * @return $this
     */
    public function add($item, array $tags = [])
    {
        if ($item instanceof Process) {
            return $this->add(new Run($item, $tags));
        }
        if (!$item instanceof RunInterface) {
            throw new InvalidArgumentException('item must implement `RunInterface`');
        }

        parent::add($item);

        $item->addListener(
            RunEvent::STARTED,
            function () {
                if ($this->state != static::STATE_NOT_STARTED) {
                    $this->started = microtime(true);
                    $this->dispatch(RunEvent::STARTED, new RunEvent($this));
                }
                $this->state = static::STATE_RUNNING;
            }
        );
        $item->addListener(
            RunEvent::COMPLETED,
            function (RunEvent $event) {
                $notFinished = array_filter(
                    $this->items,
                    function (RunInterface $run) {
                        return !$run->hasStarted() || $run->isRunning();
                    }
                );
                $this->complete[] = $event->getRun();
                $this->dispatch(RunEvent::UPDATED, new RunEvent($this));
                if (count($notFinished) === 0) {
                    $this->state = static::STATE_NOT_RUNNING;
                    $this->finished = microtime(true);
                    if ($this->isSuccessful()) {
                        $this->dispatch(RunEvent::SUCCESSFUL, new RunEvent($this));
                    } else {
                        $this->dispatch(RunEvent::FAILED, new RunEvent($this));
                    }
                    $this->dispatch(RunEvent::COMPLETED, new RunEvent($this));
                }
            }
        );
        $item->addListener(
            RunEvent::FAILED,
            function (RunEvent $event) {
                $this->exceptions[] += $event->getRun()->getExceptions();
            }
        );

        $this->pool->add($item);

        return $this;
    }

    /**
     * Has this run been started before
     *
     * @return bool
     */
    public function hasStarted()
    {
        return $this->state !== static::STATE_NOT_STARTED;
    }

    /**
     * Start all non running children
     *
     * @return $this
     *
     * @throws \Graze\ParallelProcess\Exceptions\NotRunningException
     */
    public function start()
    {
        foreach ($this->items as $run) {
            if (!$run->hasStarted()) {
                $run->start();
            }
        }
        return $this;
    }

    /**
     * Was this run successful
     *
     * @return bool
     */
    public function isSuccessful()
    {
        if ($this->state === static::STATE_NOT_RUNNING) {
            foreach ($this->items as $run) {
                if (!$run->isSuccessful()) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    /**
     * If the run was unsuccessful, get the error if applicable
     *
     * @return Exception[]|Throwable[]
     */
    public function getExceptions()
    {
        return $this->exceptions;
    }

    /**
     * We think this is running
     *
     * @return bool
     */
    public function isRunning()
    {
        return $this->state === static::STATE_RUNNING;
    }

    /**
     * Pools to see if this process is running
     *
     * @return bool
     */
    public function poll()
    {
        return $this->isRunning();
    }

    /**
     * Get a set of tags associated with this run
     *
     * @return array
     */
    public function getTags()
    {
        return $this->tags;
    }

    /**
     * @return float number of seconds this run has been running for (0 for not started)
     */
    public function getDuration()
    {
        if ($this->isRunning()) {
            return microtime(true) - $this->started;
        } elseif (!$this->hasStarted()) {
            return 0;
        }
        return $this->finished - $this->started;
    }

    /**
     * @return float[]|null an array of values of the current position, max, and percentage. null if not applicable
     */
    public function getProgress()
    {
        return [count($this->complete), count($this->items), count($this->complete) / count($this->items)];
    }

    /**
     * @return float
     */
    public function getPriority()
    {
        return $this->priority;
    }

    /**
     * @param float $priority
     *
     * @return $this
     */
    public function setPriority($priority)
    {
        $this->priority = $priority;
        return $this;
    }

    /**
     * @return string[]
     */
    protected function getEventNames()
    {
        return [
            RunEvent::STARTED,
            RunEvent::COMPLETED,
            RunEvent::SUCCESSFUL,
            RunEvent::FAILED,
            RunEvent::UPDATED,
        ];
    }
}