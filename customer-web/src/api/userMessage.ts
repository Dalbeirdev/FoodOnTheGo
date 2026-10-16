/**
 * Module 41: what a customer or a staff member may read about a failure. The messages of the app's own errors
 * (ApiError from the backend envelope, the repositories' and domains' errors) are written for people and are shown;
 * the JavaScript runtime's own errors (a TypeError from an unexpected answer, a SyntaxError, …) are developer language
 * ("Cannot read properties of undefined") and give way to the caller's friendly fallback.
 */
const RUNTIME_ERRORS = [TypeError, RangeError, ReferenceError, SyntaxError, EvalError, URIError]

export function userMessage(error: unknown, fallback: string): string {
  if (!(error instanceof Error) || RUNTIME_ERRORS.some((kind) => error instanceof kind)) return fallback
  return error.message || fallback
}
