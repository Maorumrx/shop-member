import Settings from './Settings'
import Auth from './Auth'
import Member from './Member'
import Admin from './Admin'

const Controllers = {
    Settings: Object.assign(Settings, Settings),
    Auth: Object.assign(Auth, Auth),
    Member: Object.assign(Member, Member),
    Admin: Object.assign(Admin, Admin),
}

export default Controllers