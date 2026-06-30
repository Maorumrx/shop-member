import BranchController from './BranchController'
import PackageController from './PackageController'
import MemberController from './MemberController'
import PurchaseController from './PurchaseController'

const Admin = {
    BranchController: Object.assign(BranchController, BranchController),
    PackageController: Object.assign(PackageController, PackageController),
    MemberController: Object.assign(MemberController, MemberController),
    PurchaseController: Object.assign(PurchaseController, PurchaseController),
}

export default Admin