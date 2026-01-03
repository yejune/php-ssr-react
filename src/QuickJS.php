<?php

declare(strict_types=1);

namespace PhpSsrReact;

class QuickJS
{
    private \FFI $ffi;
    private \FFI\CData $runtime;
    private \FFI\CData $context;

    private const HEADERS = <<<'C'
    typedef struct JSRuntime JSRuntime;
    typedef struct JSContext JSContext;
    typedef uint32_t JSAtom;

    typedef union JSValueUnion {
        int32_t int32;
        double float64;
        void *ptr;
    } JSValueUnion;

    typedef struct JSValue {
        JSValueUnion u;
        int64_t tag;
    } JSValue;

    // Runtime
    JSRuntime *JS_NewRuntime(void);
    void JS_FreeRuntime(JSRuntime *rt);

    // Context
    JSContext *JS_NewContext(JSRuntime *rt);
    void JS_FreeContext(JSContext *ctx);

    // Evaluation
    JSValue JS_Eval(JSContext *ctx, const char *input, size_t input_len, const char *filename, int eval_flags);

    // Value handling - use void* to prevent auto-conversion
    void *JS_ToCStringLen2(JSContext *ctx, size_t *plen, JSValue val, int cesu8);
    void JS_FreeCString(JSContext *ctx, void *ptr);
    void __JS_FreeValue(JSContext *ctx, JSValue v);

    // ToString
    JSValue JS_ToString(JSContext *ctx, JSValue val);

    // Exception handling
    JSValue JS_GetException(JSContext *ctx);
    C;

    // QuickJS tag values
    private const JS_TAG_INT = 0;
    private const JS_TAG_BOOL = 1;
    private const JS_TAG_NULL = 2;
    private const JS_TAG_UNDEFINED = 3;
    private const JS_TAG_EXCEPTION = 6;
    private const JS_TAG_FLOAT64 = 7;
    private const JS_TAG_STRING = -7;
    private const JS_TAG_OBJECT = -1;

    public function __construct(?string $libPath = null)
    {
        if ($libPath === null) {
            $ext = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
            $libPath = __DIR__ . "/../build/libquickjs.$ext";
        }

        if (!file_exists($libPath)) {
            throw new \RuntimeException("QuickJS library not found at: $libPath");
        }

        $this->ffi = \FFI::cdef(self::HEADERS, $libPath);
        $this->runtime = $this->ffi->JS_NewRuntime();
        $this->context = $this->ffi->JS_NewContext($this->runtime);
    }

    public function __destruct()
    {
        $this->ffi->JS_FreeContext($this->context);
        $this->ffi->JS_FreeRuntime($this->runtime);
    }

    public function eval(string $code, string $filename = '<eval>'): mixed
    {
        $result = $this->ffi->JS_Eval(
            $this->context,
            $code,
            strlen($code),
            $filename,
            0
        );

        return $this->jsValueToPhp($result);
    }

    private function isException(\FFI\CData $jsValue): bool
    {
        return $jsValue->tag === self::JS_TAG_EXCEPTION;
    }

    private function freeValue(\FFI\CData $jsValue): void
    {
        $this->ffi->__JS_FreeValue($this->context, $jsValue);
    }

    private function jsValueToPhp(\FFI\CData $jsValue): mixed
    {
        $tag = $jsValue->tag;

        // Check for exception first
        if ($this->isException($jsValue)) {
            $exception = $this->ffi->JS_GetException($this->context);
            $errorMsg = $this->jsValueToString($exception);
            $this->freeValue($exception);
            throw new \RuntimeException("JavaScript Error: " . ($errorMsg ?? 'Unknown error'));
        }

        // Handle different types based on tag
        switch ($tag) {
            case self::JS_TAG_INT:
                return $jsValue->u->int32;

            case self::JS_TAG_BOOL:
                return (bool)$jsValue->u->int32;

            case self::JS_TAG_NULL:
            case self::JS_TAG_UNDEFINED:
                return null;

            case self::JS_TAG_FLOAT64:
                return $jsValue->u->float64;

            default:
                // For strings, objects, and other types, convert to string
                $str = $this->jsValueToString($jsValue);
                $this->freeValue($jsValue);
                return $str;
        }
    }

    private function jsValueToString(\FFI\CData $jsValue): ?string
    {
        // Create a size_t pointer using FFI instance method
        $lenPtr = $this->ffi->new('size_t');
        $cstr = $this->ffi->JS_ToCStringLen2($this->context, \FFI::addr($lenPtr), $jsValue, 0);

        // Check if we got a null pointer
        if ($cstr === null || \FFI::isNull($cstr)) {
            return null;
        }

        // Get the length value
        $len = (int)$lenPtr->cdata;

        // Cast void* to char* and get the string
        $charPtr = $this->ffi->cast('char*', $cstr);
        $str = \FFI::string($charPtr, $len);

        $this->ffi->JS_FreeCString($this->context, $cstr);
        return $str;
    }

    public function evalFile(string $filePath): mixed
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: $filePath");
        }
        return $this->eval(file_get_contents($filePath), $filePath);
    }
}
