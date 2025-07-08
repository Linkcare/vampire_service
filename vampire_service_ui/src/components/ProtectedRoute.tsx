import { useSession } from "./SessionContext";
import Login from "./Login";

type Props = {
  children: React.ReactNode;
};

export default function ProtectedRoute({ children }: Props) {
  const { session } = useSession();

  if (!session) {
    return <Login />;
  }

  return children;
}
