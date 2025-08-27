/**
 * Componet to display a label and an input field side by side.
 * This is useful for forms where you want to have a label next to an input field.
 */
import { Field } from "@chakra-ui/react";

function LabelInputPair({
  label,
  children,
  invalid = false,
  errorMessage,
}: {
  label: string;
  children?: React.ReactNode;
  invalid?: boolean;
  errorMessage?: string;
}) {
  return (
    <>
      <Field.Root invalid={invalid}>
        {label != null && <Field.Label fontWeight="bold">{label}</Field.Label>}
        {children}
        {errorMessage != undefined && (
          <Field.ErrorText>{errorMessage}</Field.ErrorText>
        )}
      </Field.Root>
    </>
  );
}

export default LabelInputPair;
