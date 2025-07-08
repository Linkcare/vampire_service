import { Alert } from "@chakra-ui/react";

function ErrorAlert({ errorMessage }: { errorMessage: string }) {
  return (
    <Alert.Root status="error">
      <Alert.Indicator />
      <Alert.Content>
        <Alert.Title>{errorMessage}</Alert.Title>
      </Alert.Content>
    </Alert.Root>
  );
}
export default ErrorAlert;
