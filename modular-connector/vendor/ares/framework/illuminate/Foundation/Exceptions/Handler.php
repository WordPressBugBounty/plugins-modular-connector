<?php

namespace Modular\ConnectorDependencies\Illuminate\Foundation\Exceptions;

use Closure;
use Exception;
use Modular\ConnectorDependencies\Illuminate\Auth\Access\AuthorizationException;
use Modular\ConnectorDependencies\Illuminate\Auth\AuthenticationException;
use Modular\ConnectorDependencies\Illuminate\Contracts\Container\BindingResolutionException;
use Modular\ConnectorDependencies\Illuminate\Contracts\Container\Container;
use Modular\ConnectorDependencies\Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Modular\ConnectorDependencies\Illuminate\Contracts\Support\Responsable;
use Modular\ConnectorDependencies\Illuminate\Database\Eloquent\ModelNotFoundException;
use Modular\ConnectorDependencies\Illuminate\Database\MultipleRecordsFoundException;
use Modular\ConnectorDependencies\Illuminate\Database\RecordsNotFoundException;
use Modular\ConnectorDependencies\Illuminate\Http\Exceptions\HttpResponseException;
use Modular\ConnectorDependencies\Illuminate\Http\JsonResponse;
use Modular\ConnectorDependencies\Illuminate\Http\RedirectResponse;
use Modular\ConnectorDependencies\Illuminate\Http\Response;
use Modular\ConnectorDependencies\Illuminate\Routing\Router;
use Modular\ConnectorDependencies\Illuminate\Session\TokenMismatchException;
use Modular\ConnectorDependencies\Illuminate\Support\Arr;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Auth;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\View;
use Modular\ConnectorDependencies\Illuminate\Support\Reflector;
use Modular\ConnectorDependencies\Illuminate\Support\Traits\ReflectsClosures;
use Modular\ConnectorDependencies\Illuminate\Support\ViewErrorBag;
use Modular\ConnectorDependencies\Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modular\ConnectorDependencies\Psr\Log\LoggerInterface;
use Modular\ConnectorDependencies\Symfony\Component\Console\Application as ConsoleApplication;
use Modular\ConnectorDependencies\Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Modular\ConnectorDependencies\Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Modular\ConnectorDependencies\Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Modular\ConnectorDependencies\Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Modular\ConnectorDependencies\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Modular\ConnectorDependencies\Symfony\Component\HttpKernel\Exception\HttpException;
use Modular\ConnectorDependencies\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Modular\ConnectorDependencies\Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use Modular\ConnectorDependencies\Whoops\Handler\HandlerInterface;
use Modular\ConnectorDependencies\Whoops\Run as Whoops;
class Handler implements ExceptionHandlerContract
{
    use ReflectsClosures;
    /**
     * The container implementation.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $container;
    /**
     * A list of the exception types that are not reported.
     *
     * @var string[]
     */
    protected $dontReport = [];
    /**
     * The callbacks that should be used during reporting.
     *
     * @var \Illuminate\Foundation\Exceptions\ReportableHandler[]
     */
    protected $reportCallbacks = [];
    /**
     * The callbacks that should be used during rendering.
     *
     * @var \Closure[]
     */
    protected $renderCallbacks = [];
    /**
     * The registered exception mappings.
     *
     * @var array<string, \Closure>
     */
    protected $exceptionMap = [];
    /**
     * A list of the internal exception types that should not be reported.
     *
     * @var string[]
     */
    protected $internalDontReport = [AuthenticationException::class, AuthorizationException::class, HttpException::class, HttpResponseException::class, ModelNotFoundException::class, MultipleRecordsFoundException::class, RecordsNotFoundException::class, SuspiciousOperationException::class, TokenMismatchException::class, ValidationException::class];
    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var string[]
     */
    protected $dontFlash = ['current_password', 'password', 'password_confirmation'];
    /**
     * Create a new exception handler instance.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->register();
    }
    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        //
    }
    /**
     * Register a reportable callback.
     *
     * @param  callable  $reportUsing
     * @return \Illuminate\Foundation\Exceptions\ReportableHandler
     */
    public function reportable(callable $reportUsing)
    {
        if (!$reportUsing instanceof Closure) {
            $reportUsing = Closure::fromCallable($reportUsing);
        }
        return \Modular\ConnectorDependencies\tap(new ReportableHandler($reportUsing), function ($callback) {
            $this->reportCallbacks[] = $callback;
        });
    }
    /**
     * Register a renderable callback.
     *
     * @param  callable  $renderUsing
     * @return $this
     */
    public function renderable(callable $renderUsing)
    {
        if (!$renderUsing instanceof Closure) {
            $renderUsing = Closure::fromCallable($renderUsing);
        }
        $this->renderCallbacks[] = $renderUsing;
        return $this;
    }
    /**
     * Register a new exception mapping.
     *
     * @param  \Closure|string  $from
     * @param  \Closure|string|null  $to
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function map($from, $to = null)
    {
        if (is_string($to)) {
            $to = function ($exception) use ($to) {
                return new $to('', 0, $exception);
            };
        }
        if (is_callable($from) && is_null($to)) {
            $from = $this->firstClosureParameterType($to = $from);
        }
        if (!is_string($from) || !$to instanceof Closure) {
            throw new InvalidArgumentException('Invalid exception mapping.');
        }
        $this->exceptionMap[$from] = $to;
        return $this;
    }
    /**
     * Indicate that the given exception type should not be reported.
     *
     * @param  string  $class
     * @return $this
     */
    protected function ignore(string $class)
    {
        $this->dontReport[] = $class;
        return $this;
    }
    /**
     * Report or log an exception.
     *
     * @param  \Throwable  $e
     * @return void
     *
     * @throws \Throwable
     */
    public function report(Throwable $e)
    {
        $e = $this->mapException($e);
        if ($this->shouldntReport($e)) {
            return;
        }
        if (Reflector::isCallable($reportCallable = [$e, 'report'])) {
            if ($this->container->call($reportCallable) !== \false) {
                return;
            }
        }
        foreach ($this->reportCallbacks as $reportCallback) {
            if ($reportCallback->handles($e)) {
                if ($reportCallback($e) === \false) {
                    return;
                }
            }
        }
        try {
            $logger = $this->container->make(LoggerInterface::class);
        } catch (Exception $ex) {
            throw $e;
        }
        $logger->error($e->getMessage(), array_merge($this->exceptionContext($e), $this->context(), ['exception' => $e]));
    }
    /**
     * Determine if the exception should be reported.
     *
     * @param  \Throwable  $e
     * @return bool
     */
    public function shouldReport(Throwable $e)
    {
        return !$this->shouldntReport($e);
    }
    /**
     * Determine if the exception is in the "do not report" list.
     *
     * @param  \Throwable  $e
     * @return bool
     */
    protected function shouldntReport(Throwable $e)
    {
        $dontReport = array_merge($this->dontReport, $this->internalDontReport);
        return !is_null(Arr::first($dontReport, function ($type) use ($e) {
            return $e instanceof $type;
        }));
    }
    /**
     * Get the default exception context variables for logging.
     *
     * @param  \Throwable  $e
     * @return array
     */
    protected function exceptionContext(Throwable $e)
    {
        if (method_exists($e, 'context')) {
            return $e->context();
        }
        return [];
    }
    /**
     * Get the default context variables for logging.
     *
     * @return array
     */
    protected function context()
    {
        try {
            return array_filter(['userId' => Auth::id()]);
        } catch (Throwable $e) {
            return [];
        }
    }
    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $e
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */
    public function render($request, Throwable $e)
    {
        if (method_exists($e, 'render') && $response = $e->render($request)) {
            return Router::toResponse($request, $response);
        } elseif ($e instanceof Responsable) {
            return $e->toResponse($request);
        }
        $e = $this->prepareException($this->mapException($e));
        foreach ($this->renderCallbacks as $renderCallback) {
            foreach ($this->firstClosureParameterTypes($renderCallback) as $type) {
                if (is_a($e, $type)) {
                    $response = $renderCallback($e, $request);
                    if (!is_null($response)) {
                        return $response;
                    }
                }
            }
        }
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        } elseif ($e instanceof AuthenticationException) {
            return $this->unauthenticated($request, $e);
        } elseif ($e instanceof ValidationException) {
            return $this->convertValidationExceptionToResponse($e, $request);
        }
        return $this->shouldReturnJson($request, $e) ? $this->prepareJsonResponse($request, $e) : $this->prepareResponse($request, $e);
    }
    /**
     * Map the exception using a registered mapper if possible.
     *
     * @param  \Throwable  $e
     * @return \Throwable
     */
    protected function mapException(Throwable $e)
    {
        foreach ($this->exceptionMap as $class => $mapper) {
            if (is_a($e, $class)) {
                return $mapper($e);
            }
        }
        return $e;
    }
    /**
     * Prepare exception for rendering.
     *
     * @param  \Throwable  $e
     * @return \Throwable
     */
    protected function prepareException(Throwable $e)
    {
        if ($e instanceof ModelNotFoundException) {
            $e = new NotFoundHttpException($e->getMessage(), $e);
        } elseif ($e instanceof AuthorizationException) {
            $e = new AccessDeniedHttpException($e->getMessage(), $e);
        } elseif ($e instanceof TokenMismatchException) {
            $e = new HttpException(419, $e->getMessage(), $e);
        } elseif ($e instanceof SuspiciousOperationException) {
            $e = new NotFoundHttpException('Bad hostname provided.', $e);
        } elseif ($e instanceof RecordsNotFoundException) {
            $e = new NotFoundHttpException('Not found.', $e);
        }
        return $e;
    }
    /**
     * Convert an authentication exception into a response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Auth\AuthenticationException  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        return $this->shouldReturnJson($request, $exception) ? \Modular\ConnectorDependencies\response()->json(['message' => $exception->getMessage()], 401) : \Modular\ConnectorDependencies\redirect()->guest($exception->redirectTo() ?? \Modular\ConnectorDependencies\route('login'));
    }
    /**
     * Create a response object from the given validation exception.
     *
     * @param  \Illuminate\Validation\ValidationException  $e
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function convertValidationExceptionToResponse(ValidationException $e, $request)
    {
        if ($e->response) {
            return $e->response;
        }
        return $this->shouldReturnJson($request, $e) ? $this->invalidJson($request, $e) : $this->invalid($request, $e);
    }
    /**
     * Convert a validation exception into a response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Validation\ValidationException  $exception
     * @return \Illuminate\Http\Response
     */
    protected function invalid($request, ValidationException $exception)
    {
        return \Modular\ConnectorDependencies\redirect($exception->redirectTo ?? url()->previous())->withInput(Arr::except($request->input(), $this->dontFlash))->withErrors($exception->errors(), $request->input('_error_bag', $exception->errorBag));
    }
    /**
     * Convert a validation exception into a JSON response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Validation\ValidationException  $exception
     * @return \Illuminate\Http\JsonResponse
     */
    protected function invalidJson($request, ValidationException $exception)
    {
        return \Modular\ConnectorDependencies\response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], $exception->status);
    }
    /**
     * Determine if the exception handler response should be JSON.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $e
     * @return bool
     */
    protected function shouldReturnJson($request, Throwable $e)
    {
        return $request->expectsJson();
    }
    /**
     * Prepare a response for the given exception.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $e
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function prepareResponse($request, Throwable $e)
    {
        if (!$this->isHttpException($e) && \Modular\ConnectorDependencies\config('app.debug')) {
            return $this->toIlluminateResponse($this->convertExceptionToResponse($e), $e);
        }
        if (!$this->isHttpException($e)) {
            $e = new HttpException(500, $e->getMessage());
        }
        return $this->toIlluminateResponse($this->renderHttpException($e), $e);
    }
    /**
     * Create a Symfony response for the given exception.
     *
     * @param  \Throwable  $e
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function convertExceptionToResponse(Throwable $e)
    {
        return new SymfonyResponse($this->renderExceptionContent($e), $this->isHttpException($e) ? $e->getStatusCode() : 500, $this->isHttpException($e) ? $e->getHeaders() : []);
    }
    /**
     * Get the response content for the given exception.
     *
     * @param  \Throwable  $e
     * @return string
     */
    protected function renderExceptionContent(Throwable $e)
    {
        try {
            return \Modular\ConnectorDependencies\config('app.debug') && class_exists(Whoops::class) ? $this->renderExceptionWithWhoops($e) : $this->renderExceptionWithSymfony($e, \Modular\ConnectorDependencies\config('app.debug'));
        } catch (Exception $e) {
            return $this->renderExceptionWithSymfony($e, \Modular\ConnectorDependencies\config('app.debug'));
        }
    }
    /**
     * Render an exception to a string using "Whoops".
     *
     * @param  \Throwable  $e
     * @return string
     */
    protected function renderExceptionWithWhoops(Throwable $e)
    {
        return \Modular\ConnectorDependencies\tap(new Whoops(), function ($whoops) {
            $whoops->appendHandler($this->whoopsHandler());
            $whoops->writeToOutput(\false);
            $whoops->allowQuit(\false);
        })->handleException($e);
    }
    /**
     * Get the Whoops handler for the application.
     *
     * @return \Whoops\Handler\Handler
     */
    protected function whoopsHandler()
    {
        try {
            return \Modular\ConnectorDependencies\app(HandlerInterface::class);
        } catch (BindingResolutionException $e) {
            return (new WhoopsHandler())->forDebug();
        }
    }
    /**
     * Render an exception to a string using Symfony.
     *
     * @param  \Throwable  $e
     * @param  bool  $debug
     * @return string
     */
    protected function renderExceptionWithSymfony(Throwable $e, $debug)
    {
        $renderer = new HtmlErrorRenderer($debug);
        return $renderer->render($e)->getAsString();
    }
    /**
     * Render the given HttpException.
     *
     * @param  \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface  $e
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function renderHttpException(HttpExceptionInterface $e)
    {
        $this->registerErrorViewPaths();
        if (View::exists($view = $this->getHttpExceptionView($e))) {
            return \Modular\ConnectorDependencies\response()->view($view, ['errors' => new ViewErrorBag(), 'exception' => $e], $e->getStatusCode(), $e->getHeaders());
        }
        return $this->convertExceptionToResponse($e);
    }
    /**
     * Register the error template hint paths.
     *
     * @return void
     */
    protected function registerErrorViewPaths()
    {
        (new RegisterErrorViewPaths())();
    }
    /**
     * Get the view used to render HTTP exceptions.
     *
     * @param  \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface  $e
     * @return string
     */
    protected function getHttpExceptionView(HttpExceptionInterface $e)
    {
        return "errors::{$e->getStatusCode()}";
    }
    /**
     * Map the given exception into an Illuminate response.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @param  \Throwable  $e
     * @return \Illuminate\Http\Response
     */
    protected function toIlluminateResponse($response, Throwable $e)
    {
        if ($response instanceof SymfonyRedirectResponse) {
            $response = new RedirectResponse($response->getTargetUrl(), $response->getStatusCode(), $response->headers->all());
        } else {
            $response = new Response($response->getContent(), $response->getStatusCode(), $response->headers->all());
        }
        return $response->withException($e);
    }
    /**
     * Prepare a JSON response for the given exception.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $e
     * @return \Illuminate\Http\JsonResponse
     */
    protected function prepareJsonResponse($request, Throwable $e)
    {
        return new JsonResponse($this->convertExceptionToArray($e), $this->isHttpException($e) ? $e->getStatusCode() : 500, $this->isHttpException($e) ? $e->getHeaders() : [], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }
    /**
     * Convert the given exception to an array.
     *
     * @param  \Throwable  $e
     * @return array
     */
    protected function convertExceptionToArray(Throwable $e)
    {
        return \Modular\ConnectorDependencies\config('app.debug') ? ['message' => $e->getMessage(), 'exception' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => \Modular\ConnectorDependencies\collect($e->getTrace())->map(function ($trace) {
            return Arr::except($trace, ['args']);
        })->all()] : ['message' => $this->isHttpException($e) ? $e->getMessage() : 'Server Error'];
    }
    /**
     * Render an exception to the console.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Throwable  $e
     * @return void
     */
    public function renderForConsole($output, Throwable $e)
    {
        (new ConsoleApplication())->renderThrowable($e, $output);
    }
    /**
     * Determine if the given exception is an HTTP exception.
     *
     * @param  \Throwable  $e
     * @return bool
     */
    protected function isHttpException(Throwable $e)
    {
        return $e instanceof HttpExceptionInterface;
    }
}
