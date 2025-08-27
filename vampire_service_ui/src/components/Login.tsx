import { useState } from "react";
import { Center, Input, Button, VStack, Alert } from "@chakra-ui/react";
import PageHeader from "../components/PageBuilder/PageHeader";
import { SessionProps, useSession } from "../components/SessionContext";
import { session_init } from "../services/LinkcareAPI/Session";
import { initializeVampireService } from "../services/VampireService/VampireService";
import { user_get_contact } from "../services/LinkcareAPI/User";
import { fromStoredSession } from "../services/LinkcareAPI/LinkcareApi";

function Login() {
  const { setSession } = useSession();
  const [username, setUsername] = useState<string>("");
  const [password, setPassword] = useState<string>("");
  const [loginError, setLoginError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    try {
      const apiSession = await session_init(username, password);
      const contactData = await user_get_contact(apiSession.user);
      const sessionData: SessionProps = {
        username: username || "",
        userId: apiSession.user,
        name: (
          (contactData.name?.given_name || "") +
          " " +
          (contactData.name?.family_name || "")
        ).trim(),
        token: apiSession.token || "",
        teamId: apiSession.team || null,
        teamName: apiSession.team_code || "",
        roleId: apiSession.role || null,
      };

      setSession(sessionData);
      fromStoredSession(sessionData.token);
      initializeVampireService(sessionData.token);
      setLoginError(null);
    } catch (error) {
      setLoginError(
        error instanceof Error ? error.message : "An unexpected error occurred"
      );
    }
  };

  return (
    <>
      <PageHeader>LOG IN</PageHeader>
      <Center h="100vh" w="100vw" bg="bg.emphasized">
        <VStack p="15px">
          {loginError && (
            <Alert.Root status="error">
              <Alert.Indicator />
              <Alert.Content>
                <Alert.Title>{loginError}</Alert.Title>
              </Alert.Content>
            </Alert.Root>
          )}
          <form onSubmit={handleSubmit}>
            <VStack w="80">
              <Input
                placeholder="username"
                variant="subtle"
                onChange={(e) => setUsername(e.target.value)}
              />
              <Input
                type="password"
                placeholder="password"
                onChange={(e) => setPassword(e.target.value)}
              />
              <Button type="submit">Submit</Button>
            </VStack>
          </form>
        </VStack>
      </Center>
    </>
  );
}

export default Login;
